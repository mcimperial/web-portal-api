<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupStaleSubmittedEnrollments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage examples:
     *   php artisan enrollment:cleanup-stale-submitted --dry-run
     *   php artisan enrollment:cleanup-stale-submitted --older-than-days=2 --force
     *   php artisan enrollment:cleanup-stale-submitted --enrollment-id=123 --force
     */
    protected $signature = 'enrollment:cleanup-stale-submitted
        {--dry-run : Only report stale SUBMITTED rows without updating them}
        {--force : Apply the cleanup to stale SUBMITTED rows}
        {--older-than-days=1 : Only process records older than this many days}
        {--enrollment-id= : Optional enrollment id filter}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up stale SUBMITTED principal and dependent rows that should no longer remain submitted after a missed scheduled export.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $olderThanDays = max(0, (int) $this->option('older-than-days'));
        $threshold = now()->subDays($olderThanDays);
        $enrollmentId = $this->option('enrollment-id');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        if (!$dryRun && !$force) {
            $this->warn('No action selected. Re-run with --force to apply changes or --dry-run to preview only.');
            return self::FAILURE;
        }

        $principalQuery = DB::table('cm_principal')
            ->where('enrollment_status', 'SUBMITTED')
            ->where('status', 'ACTIVE')
            ->whereNotNull('updated_at')
            ->where('updated_at', '<=', $threshold);

        $dependentQuery = DB::table('cm_dependent')
            ->where('enrollment_status', 'SUBMITTED')
            ->where('status', 'ACTIVE')
            ->whereNotNull('updated_at')
            ->where('updated_at', '<=', $threshold);

        if ($enrollmentId !== null && $enrollmentId !== '') {
            $principalQuery->where('enrollment_id', (int) $enrollmentId);
            $dependentQuery->whereIn('principal_id', function ($subQuery) use ($enrollmentId) {
                $subQuery->select('id')
                    ->from('cm_principal')
                    ->where('enrollment_id', (int) $enrollmentId);
            });
        }

        $stalePrincipals = $principalQuery->pluck('id')->toArray();
        $staleDependents = $dependentQuery->pluck('id')->toArray();

        if (empty($stalePrincipals) && empty($staleDependents)) {
            $this->info('No stale SUBMITTED rows found older than ' . $olderThanDays . ' day(s).');
            return self::SUCCESS;
        }

        $this->info('Found stale SUBMITTED rows older than ' . $olderThanDays . ' day(s):');
        $this->line('  Principals: ' . count($stalePrincipals));
        $this->line('  Dependents: ' . count($staleDependents));

        if ($dryRun) {
            $this->warn('Dry run only. No records were changed.');
            return self::SUCCESS;
        }

        $updatedPrincipals = 0;
        $updatedDependents = 0;

        if (!empty($stalePrincipals)) {
            $updatedPrincipals = DB::table('cm_principal')
                ->whereIn('id', $stalePrincipals)
                ->update([
                    'enrollment_status' => 'FOR-APPROVAL',
                    'updated_at' => now(),
                ]);
        }

        if (!empty($staleDependents)) {
            $updatedDependents = DB::table('cm_dependent')
                ->whereIn('id', $staleDependents)
                ->update([
                    'enrollment_status' => 'FOR-APPROVAL',
                    'updated_at' => now(),
                ]);
        }

        $this->info('Cleanup applied successfully.');
        $this->line('  Principals updated: ' . $updatedPrincipals);
        $this->line('  Dependents updated: ' . $updatedDependents);

        return self::SUCCESS;
    }
}
