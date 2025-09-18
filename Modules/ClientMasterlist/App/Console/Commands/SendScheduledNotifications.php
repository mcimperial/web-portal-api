<?php

namespace Modules\ClientMasterlist\App\Console\Commands;

use Illuminate\Console\Command;
use Modules\ClientMasterlist\App\Http\Controllers\SendNotificationController;

class SendScheduledNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:send-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send all scheduled notifications that are due.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $controller = new SendNotificationController();
        $response = $controller->sendScheduled();
        $this->info('Scheduled notifications processed.');
    }
}
