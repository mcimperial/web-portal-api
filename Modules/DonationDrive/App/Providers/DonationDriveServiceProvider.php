<?php

namespace Modules\DonationDrive\App\Providers;

use Illuminate\Support\ServiceProvider;

class DonationDriveServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path('DonationDrive', 'Database/migrations'));
        $this->loadViewsFrom(module_path('DonationDrive', 'resources/views'), 'donationdrive');
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }
}
