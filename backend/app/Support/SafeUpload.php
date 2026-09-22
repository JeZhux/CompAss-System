<?php

namespace App\Support;

use App\Exceptions\BusinessRuleConflictException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Filename hardening for upload storage keys and download disposition (F-03).
 *
 * Client original names are attacker-controlled: they may carry path
 * separators, null bytes, control characters, or CRLF sequences. Storage
 * keys must stay confined to the intended directory and unique per upload;
 * download names must not permit header injection or path disclosure.
 */
final class SafeUpload
{
    /**
     * Maximum persisted storage-key length, matching the varchar(255)
     * filename columns. Longer keys are rejected with FILENAME_TOO_LONG
     * before any file is written, so a DB length reject can never orphan
     * a file on disk.
     */
    public const MAX_STORAGE_KEY_LENGTH = 255;

    /**
     * Default storage disk honoring FILESYSTEM_DISK (local dev, s3/Supabase
     * in prod). All upload/download paths resolve through this so keys stay
     * S3-compatible (no local paths, no public URLs).
     */
    public static function disk()
    {
        return Storage::disk(config('filesystems.default', 'local'));
    }

    /**
     * Build a confined, unique storage key for an uploaded file.
     */
    public static function storageKey(string $dir, UploadedFile $file): string
    {
        $dir = trim(str_replace('\\', '/', $dir), '/');
        $raw = (string) $file->getClientOriginalName();
        $sanitized = self::sanitizeStorageBasename($raw);

        if (self::containsRisky($raw)) {
            Log::warning('Upload filename required sanitization.', [
                'dir' => $dir,
                'original' => self::loggable($raw),
                'sanitized' => $sanitized,
            ]);
        }

        $key = $dir . '/' . self::uniquePrefix() . '_' . $sanitized;

        if (mb_strlen($key) > self::MAX_STORAGE_KEY_LENGTH) {
            Log::warning('Upload filename too long to store.', [
                'dir' => $dir,
                'length' => mb_strlen($key),
            ]);

            throw new BusinessRuleConflictException(
                'The file name is too long to store.',
                'FILENAME_TOO_LONG',
                422
            );
        }

        return $key;
    }

    /**
     * Time-ordered unique prefix strengthened with cryptographic randomness.
     * uniqid() alone can collide in tight loops on low-resolution timers;
     * the random suffix keeps same-batch same-name uploads distinct and
     * unguessable. Falls back to uniqid('', true) when random_bytes fails.
     */
    public static function uniquePrefix(): string
    {
        try {
            return uniqid() . bin2hex(random_bytes(4));
        } catch (\Throwable) {
            return str_replace('.', '', uniqid('', true));
        }
    }

    /**
     * Early length gate for callers that must reject before any file is
     * written (batch uploads). Uses the same limit and sanitization as
     * storageKey() so the prediction matches the real key length.
     */
    public static function assertFits(string $dir, UploadedFile $file): void
    {
        $dir = trim(str_replace('\\', '/', $dir), '/');
        $sanitized = self::sanitizeStorageBasename((string) $file->getClientOriginalName());
        $length = mb_strlen($dir . '/' . self::uniquePrefix() . '_' . $sanitized);

        if ($length > self::MAX_STORAGE_KEY_LENGTH) {
            Log::warning('Upload filename too long to store.', [
                'dir' => $dir,
                'length' => $length,
            ]);

            throw new BusinessRuleConflictException(
                'The file name is too long to store.',
                'FILENAME_TOO_LONG',
                422
            );
        }
    }

    /**
     * Strip directories/controls and whitelist the storage basename.
     */
    public static function sanitizeStorageBasename(string $raw): string
    {
        $raw = str_replace("\0", '', $raw);
        $base = basename(str_replace('\\', '/', $raw));
        $base = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $base);
        $base = trim($base);

        if ($base === '' || $base === '.' || $base === '..') {
            return 'file';
        }

        $sanitized = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $base);

        if (str_starts_with($sanitized, '.')) {
            $sanitized = '_' . substr($sanitized, 1);
        }

        if ($sanitized === '' || $sanitized === '.' || $sanitized === '..') {
            return 'file';
        }

        return $sanitized;
    }

    /**
     * Build a header-safe download name that round-trips display.
     */
    public static function downloadName(?string $raw, string $fallback = 'download'): string
    {
        $raw = (string) ($raw ?? '');
        $raw = str_replace("\0", '', $raw);
        $base = basename(str_replace('\\', '/', $raw));
        $base = str_replace(["\r", "\n"], '', $base);
        $base = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $base);
        $base = str_replace(['"', ';'], '_', $base);
        $base = trim($base);

        if (str_starts_with($base, '.')) {
            $base = '_' . substr($base, 1);
        }

        if ($base === '' || $base === '.' || $base === '..') {
            return $fallback;
        }

        return $base;
    }

    /**
     * True when a persisted key stays inside the intended directory.
     */
    public static function isConfined(string $path, string $dir): bool
    {
        $nPath = str_replace('\\', '/', (string) $path);
        $nDir = trim(str_replace('\\', '/', (string) $dir), '/');

        if ($nPath === '' || $nDir === '') {
            return false;
        }

        if (str_contains($nPath, "\0")) {
            return false;
        }

        if (str_starts_with($nPath, '/')) {
            return false;
        }

        if (! str_starts_with($nPath, $nDir . '/')) {
            return false;
        }

        $rest = substr($nPath, strlen($nDir) + 1);

        if ($rest === '') {
            return false;
        }

        foreach (explode('/', $rest) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strip controls/newlines so attacker bytes never reach logs raw.
     */
    public static function loggable(string $value, int $max = 120): string
    {
        $value = str_replace("\0", '', $value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);
        $value = trim($value);

        if (mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max) . '…';
        }

        return $value;
    }

    /**
     * Risky only means path/control/header confusion — not ordinary spaces.
     */
    private static function containsRisky(string $raw): bool
    {
        if (str_contains($raw, "\0")) {
            return true;
        }

        if (str_contains($raw, '/') || str_contains($raw, '\\')) {
            return true;
        }

        if (str_contains($raw, '..')) {
            return true;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $raw) === 1) {
            return true;
        }

        if (str_contains($raw, '"') || str_contains($raw, ';')) {
            return true;
        }

        return false;
    }
}
