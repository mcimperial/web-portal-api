<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off enrollment correction command, safe to run through Artisan.
 *
 * Usage:
 *   php artisan enrollment:fix-principal-status
 */
class FixPrincipalEnrollmentStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'enrollment:fix-principal-status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset employment_end_date and set APPROVED status for specific inactive resigned principal enrollments';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Running principal enrollment status correction...');

        $affected = DB::update(
            "UPDATE llibiapp_web_portal.cm_principal
            SET employment_end_date = NULL,
                enrollment_status = 'APPROVED'
            WHERE enrollment_id IN (1, 2)
              AND employment_end_date IS NOT NULL
              AND enrollment_status = 'RESIGNED'
              AND status = 'INACTIVE'"
        );

        $this->info("Done. Affected rows: {$affected}.");

        return self::SUCCESS;
    }
}
