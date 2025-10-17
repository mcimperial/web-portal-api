<?php

namespace Modules\ClientMasterlist\App\Services;

use Carbon\Carbon;
use Cron\CronExpression;
use Illuminate\Support\Facades\Log;
use Modules\ClientMasterlist\App\Models\Notification;

/**
 * SchedulerService
 * 
 * Handles cron scheduling, date range calculations, and timing logic for notifications.
 * Manages scheduled notification processing and date-based filtering.
 */
class SchedulerService
{
    /**
     * Check if a cron expression is due now
     * 
     * @param string $cronExpression The cron expression to check
     * @param \DateTime|null $now The current time (defaults to now)
     * @return bool True if the cron is due
     */
    public function isCronDue($cronExpression, $now = null)
    {
        try {
            $now = $now ?: now();
            $cron = CronExpression::factory($cronExpression);
            return $cron->isDue($now);
        } catch (\Exception $e) {
            Log::warning("Invalid cron expression", [
                'expression' => $cronExpression,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get due notifications that should be processed
     * 
     * @param \DateTime|null $now The current time
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getDueNotifications($now = null)
    {
        $now = $now ?: now();

        return Notification::whereNotNull('schedule')
            ->whereNull('deleted_at')
            ->get()
            ->filter(function ($notification) use ($now) {
                return $this->isCronDue($notification->schedule, $now);
            });
    }

    /**
     * Calculate date range from previous scheduled time to current time
     * Based on the notification's cron schedule interval
     * 
     * @param Notification|null $notification The notification with schedule
     * @param \DateTime|null $now The current time
     * @return array Array with 'from' and 'to' date strings
     */
    public function calculateDateRangeFromSchedule($notification = null, $now = null)
    {
        $now = $now ?: now();

        if (!$notification || !$notification->schedule) {
            return $this->getDefaultDateRange($now);
        }

        try {
            $cronExp = CronExpression::factory($notification->schedule);
            $currentScheduledTime = Carbon::instance($cronExp->getPreviousRunDate($now));

            $dateFrom = $this->calculateFromDate($notification->schedule, $currentScheduledTime);

            Log::info("Date range calculated from schedule", [
                'schedule' => $notification->schedule,
                'current_scheduled' => $currentScheduledTime->format('Y-m-d H:i:s'),
                'from' => $dateFrom->format('Y-m-d H:i:s'),
                'to' => $now->format('Y-m-d H:i:s'),
                'notification_type' => $notification->notification_type ?? 'unknown'
            ]);

            return [
                'from' => $dateFrom->format('Y-m-d H:i:s'),
                'to' => $now->format('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            Log::warning("Failed to parse cron schedule, using default date range", [
                'schedule' => $notification->schedule,
                'error' => $e->getMessage()
            ]);

            return $this->getDefaultDateRange($now);
        }
    }

    /**
     * Get default date range (yesterday start of day to now)
     */
    private function getDefaultDateRange($now)
    {
        return [
            'from' => $now->copy()->subDay()->startOfDay()->format('Y-m-d H:i:s'),
            'to' => $now->format('Y-m-d H:i:s')
        ];
    }

    /**
     * Calculate the "from" date based on cron schedule interval
     */
    private function calculateFromDate($schedule, $currentScheduledTime)
    {
        $scheduleArray = explode(' ', $schedule);
        $minutes = $scheduleArray[0] ?? '*';
        $hours = $scheduleArray[1] ?? '*';
        $days = $scheduleArray[2] ?? '*';
        $months = $scheduleArray[3] ?? '*';
        $weekdays = $scheduleArray[4] ?? '*';

        $dateFrom = $currentScheduledTime->copy();

        // Determine interval based on cron pattern
        if ($minutes !== '*' && $hours === '*') {
            // Every minute - subtract 1 minute
            $dateFrom->subMinutes(1);
        } elseif ($hours === '*') {
            // Hourly - subtract 1 hour
            $dateFrom->subHours(1);
        } elseif ($days === '*') {
            // Daily - subtract 1 day
            $dateFrom->subDays(1);
        } elseif ($months === '*') {
            // Monthly - subtract 1 month
            $dateFrom->subMonths(1);
        } else {
            // Default to 1 day for other patterns
            $dateFrom->subDays(1);
        }

        return $dateFrom;
    }

    /**
     * Get next run time for a cron expression
     * 
     * @param string $cronExpression The cron expression
     * @param \DateTime|null $from The base time (defaults to now)
     * @return \DateTime|null Next run time or null on error
     */
    public function getNextRunTime($cronExpression, $from = null)
    {
        try {
            $from = $from ?: now();
            $cron = CronExpression::factory($cronExpression);
            return Carbon::instance($cron->getNextRunDate($from));
        } catch (\Exception $e) {
            Log::warning("Failed to get next run time", [
                'expression' => $cronExpression,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get previous run time for a cron expression
     * 
     * @param string $cronExpression The cron expression
     * @param \DateTime|null $from The base time (defaults to now)
     * @return \DateTime|null Previous run time or null on error
     */
    public function getPreviousRunTime($cronExpression, $from = null)
    {
        try {
            $from = $from ?: now();
            $cron = CronExpression::factory($cronExpression);
            return Carbon::instance($cron->getPreviousRunDate($from));
        } catch (\Exception $e) {
            Log::warning("Failed to get previous run time", [
                'expression' => $cronExpression,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Validate a cron expression
     * 
     * @param string $cronExpression The cron expression to validate
     * @return bool True if valid
     */
    public function isValidCronExpression($cronExpression)
    {
        try {
            CronExpression::factory($cronExpression);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get human-readable description of cron schedule
     * 
     * @param string $cronExpression The cron expression
     * @return string Human-readable description
     */
    public function describeCronExpression($cronExpression)
    {
        if (!$this->isValidCronExpression($cronExpression)) {
            return 'Invalid cron expression';
        }

        $scheduleArray = explode(' ', $cronExpression);
        $minutes = $scheduleArray[0] ?? '*';
        $hours = $scheduleArray[1] ?? '*';
        $days = $scheduleArray[2] ?? '*';
        $months = $scheduleArray[3] ?? '*';
        $weekdays = $scheduleArray[4] ?? '*';

        // Simple pattern recognition
        if ($minutes !== '*' && $hours === '*' && $days === '*' && $months === '*' && $weekdays === '*') {
            return "Every minute at {$minutes} seconds";
        }

        if ($hours !== '*' && $days === '*' && $months === '*' && $weekdays === '*') {
            $minute = $minutes === '*' ? '00' : $minutes;
            return "Daily at {$hours}:{$minute}";
        }

        if ($weekdays !== '*' && $months === '*') {
            $minute = $minutes === '*' ? '00' : $minutes;
            $hour = $hours === '*' ? '00' : $hours;
            return "Weekly on day {$weekdays} at {$hour}:{$minute}";
        }

        if ($days !== '*' && $months === '*') {
            $minute = $minutes === '*' ? '00' : $minutes;
            $hour = $hours === '*' ? '00' : $hours;
            return "Monthly on day {$days} at {$hour}:{$minute}";
        }

        return "Custom schedule: {$cronExpression}";
    }

    /**
     * Check if notification should be processed based on time constraints
     * 
     * @param Notification $notification The notification to check
     * @param \DateTime|null $now Current time
     * @return bool True if should be processed
     */
    public function shouldProcessNotification($notification, $now = null)
    {
        $now = $now ?: now();

        // Check if cron schedule is due
        if (!$this->isCronDue($notification->schedule, $now)) {
            return false;
        }

        // Add any additional time-based constraints here
        // For example, check if enough time has passed since last_sent_at

        return true;
    }

    /**
     * Update notification last sent time
     * 
     * @param Notification $notification The notification to update
     * @param \DateTime|null $sentAt The time it was sent (defaults to now)
     * @return bool True if updated successfully
     */
    public function updateLastSentTime($notification, $sentAt = null)
    {
        try {
            $sentAt = $sentAt ?: now();
            $notification->last_sent_at = $sentAt;
            $notification->save();

            Log::info("Updated notification last_sent_at", [
                'notification_id' => $notification->id,
                'last_sent_at' => $sentAt->format('Y-m-d H:i:s')
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Failed to update notification last_sent_at", [
                'notification_id' => $notification->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
