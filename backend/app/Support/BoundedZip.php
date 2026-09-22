<?php

namespace App\Support;

/**
 * Zip-bomb guard for OOXML entry reads (F-05 hardening).
 *
 * Central-directory sizes may lie and `ZipArchive::getFromName()` returns
 * the whole decompressed entry as one string, so neither is safe for
 * attacker-controlled archives: one small upload could otherwise
 * materialize gigabytes. This helper decompresses incrementally via
 * `ZipArchive::getStream()` and copies to a temp file in 64 KiB chunks,
 * aborting the moment a cap is breached — PHP memory stays flat no
 * matter how large the entry claims or is.
 */
final class BoundedZip
{
    /**
     * Absolute cap on decompressed bytes per XML entry. A 5000-row import
     * sheet (or 200k-char document.xml) serializes to well under 1 MB;
     * 8 MiB leaves an order of magnitude of headroom for formatting
     * overhead while keeping any single entry safely small.
     */
    public const MAX_ENTRY_BYTES = 8 * 1024 * 1024;

    /**
     * Expansion-ratio cap: decompressed entry bytes may not exceed this
     * multiple of the whole compressed file. Genuine OOXML text XML
     * compresses ~5–20x; 100x only trips on bomb-shaped content.
     */
    public const MAX_EXPANSION_RATIO = 100;

    /**
     * Distinguishes cap breaches from missing/unreadable entries when
     * catching the RuntimeException below.
     */
    public const E_TOO_LARGE = 1;

    /**
     * Copy one zip entry to a temp file without ever holding the
     * decompressed bytes in memory.
     *
     * The central-directory stated size is checked first so an
     * honestly-declared oversized entry is rejected without
     * decompressing a byte; the running byte count during the chunked
     * copy is authoritative since stated sizes may lie (or be zero with
     * data-descriptor entries).
     *
     * @param string $zipPath         Absolute path to the archive.
     * @param string $entryName       Entry path inside the archive.
     * @param int    $compressedBytes Compressed size of the whole file
     *   (ratio guard; <= 0 disables the ratio check, absolute cap stays).
     * @return string Absolute temp-file path holding the entry bytes (caller must unlink).
     *
     * @throws \RuntimeException with code E_TOO_LARGE on cap breach (message
     *   carries only the entry name and caps, never content); plain
     *   RuntimeException when the archive/entry cannot be read.
     */
    public static function copyEntryToTempFile(string $zipPath, string $entryName, int $compressedBytes): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Unable to open archive.');
        }

        try {
            $stat = $zip->statName($entryName);
            if ($stat === false) {
                throw new \RuntimeException('Archive entry missing: ' . $entryName);
            }

            $ratioCap = $compressedBytes > 0
                ? self::MAX_EXPANSION_RATIO * $compressedBytes
                : PHP_INT_MAX;
            if (($stat['size'] ?? 0) > self::MAX_ENTRY_BYTES || ($stat['size'] ?? 0) > $ratioCap) {
                throw new \RuntimeException(
                    'Archive entry exceeds decompressed size cap: ' . $entryName,
                    self::E_TOO_LARGE
                );
            }

            $stream = $zip->getStream($entryName);
            if ($stream === false) {
                throw new \RuntimeException('Unable to read archive entry: ' . $entryName);
            }

            $tmp = tempnam(sys_get_temp_dir(), 'zipentry_');
            if ($tmp === false) {
                fclose($stream);

                throw new \RuntimeException('Unable to create temp file.');
            }

            $out = fopen($tmp, 'wb');
            if ($out === false) {
                fclose($stream);
                @unlink($tmp);

                throw new \RuntimeException('Unable to write temp file.');
            }

            try {
                $total = 0;
                while (! feof($stream)) {
                    $chunk = fread($stream, 65536);
                    if ($chunk === false) {
                        throw new \RuntimeException('Unable to read archive entry: ' . $entryName);
                    }
                    if ($chunk === '') {
                        continue;
                    }
                    $total += strlen($chunk);
                    if ($total > self::MAX_ENTRY_BYTES || $total > $ratioCap) {
                        throw new \RuntimeException(
                            'Archive entry exceeds decompressed size cap: ' . $entryName,
                            self::E_TOO_LARGE
                        );
                    }
                    $written = 0;
                    $length = strlen($chunk);
                    while ($written < $length) {
                        $n = fwrite($out, substr($chunk, $written));
                        if ($n === false) {
                            throw new \RuntimeException('Unable to write temp file.');
                        }
                        $written += $n;
                    }
                }
            } catch (\Throwable $e) {
                fclose($stream);
                fclose($out);
                @unlink($tmp);

                throw $e;
            }

            fclose($stream);
            fclose($out);

            return $tmp;
        } finally {
            $zip->close();
        }
    }
}
