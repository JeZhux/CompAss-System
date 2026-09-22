<?php

namespace App\Console\Commands;

use App\Models\ImportJob;
use App\Services\BatchImportService;
use Illuminate\Console\Command;

/**
 * Backfill the ARCH-002 FR-031 error-report single-use contract for legacy import_jobs
 * created before the token/expiry columns existed.
 *
 * For every import_jobs row with an error_report_path but no
 * error_report_token, derives the token from the filename (sha256 of the
 * stored token) and assigns the same 7-day TTL constant the live path uses,
 * so pre-existing reports become downloadable and single-use like new ones.
 *
 * Registered in routes/console.php.
 *
 * @Traced-To ARCH-002 FR-031 (error-report single-use download contract;
 *   token single-use + expiry columns), ARCH-004 §8.1 (import error reports:
 *   single-use, 7-day expiry)
 */
class BackfillImportErrorReportTokens extends Command
{
    protected $signature = 'import:backfill-error-report-tokens';

    protected $description = 'Backfill error_report_token and error_report_expires_at '
        . 'for legacy import_jobs.';

    public function handle(): int
    {
        $updated = 0;

        ImportJob::whereNotNull('error_report_path')
            ->whereNull('error_report_token')
            ->chunkById(500, function ($jobs) use (&$updated): void {
                foreach ($jobs as $job) {
                    $token = basename($job->error_report_path, '.xlsx');
                    if ($token === '') {
                        continue;
                    }

                    // TTL is anchored to the job's creation time so legacy
                    // reports expire on the same schedule as new ones.
                    $job->update([
                        'error_report_token' => hash('sha256', $token),
                        'error_report_expires_at' => $job->created_at
                            ->addDays(BatchImportService::ERROR_REPORT_TTL_DAYS),
                    ]);
                    $updated++;
                }
            });

        $this->info("Backfilled {$updated} import error-report tokens.");

        return self::SUCCESS;
    }
}
