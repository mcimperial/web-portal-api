<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        if ((getenv('APP_ENV') ?: '') === 'testing') {
            putenv('DB_CONNECTION=sqlite');
            putenv('DB_DATABASE=:memory:');
            $_ENV['DB_CONNECTION'] = 'sqlite';
            $_ENV['DB_DATABASE'] = ':memory:';
            $_SERVER['DB_CONNECTION'] = 'sqlite';
            $_SERVER['DB_DATABASE'] = ':memory:';
        }

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if ((getenv('APP_ENV') ?: '') === 'testing') {
            if ($app->configurationIsCached()) {
                throw new RuntimeException(
                    'Testing aborted: config cache is enabled. Run `php artisan config:clear` before `php artisan test` to avoid using non-testing DB settings.'
                );
            }

            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections.sqlite.database', ':memory:');
            $app['db']->purge('sqlite');
        }

        return $app;
    }
}
