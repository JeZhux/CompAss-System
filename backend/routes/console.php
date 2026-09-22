<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('import:backfill-error-report-tokens', function () {
    $this->call(\App\Console\Commands\BackfillImportErrorReportTokens::class);
})->purpose('Backfill error_report_token/expiry for legacy import_jobs (ARCH-002 FR-031 error-report single-use time-limited download contract)');
