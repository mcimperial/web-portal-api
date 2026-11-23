<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Modules\ClientMobileApp\App\Http\Controllers\HospitalController;
use Modules\ClientMobileApp\App\Providers\ClientMobileAppServiceProvider;
use Tests\TestCase;

class MobileModuleConsolidationTest extends TestCase
{
    public function test_mobile_data_routes_and_configuration_remain_available(): void
    {
        $route = Route::getRoutes()->getByName('api.mobile.data.hospitals.show');

        $this->assertNotNull($route);
        $this->assertSame('api/v1/mobile/hospitals/{id}', $route->uri());
        $this->assertSame(HospitalController::class.'@show', $route->getActionName());
        $this->assertContains('auth:mobile', $route->gatherMiddleware());
        $this->assertContains('mobile.user', $route->gatherMiddleware());
        $this->assertSame('[0-9]+', $route->wheres['id']);
        $this->getJson('/api/v1/mobile/hospitals')->assertUnauthorized();
    }

    public function test_legacy_configuration_overrides_are_preserved_with_defaults(): void
    {
        config(['clientmobiledata' => ['per_page' => 37]]);

        (new ClientMobileAppServiceProvider($this->app))->boot();

        $this->assertSame(37, config('clientmobiledata.per_page'));
        $this->assertSame(100, config('clientmobiledata.max_per_page'));
    }

    public function test_moved_migrations_are_not_rerun_when_already_recorded(): void
    {
        config(['database.connections.mysql' => config('database.connections.sqlite')]);
        DB::purge('mysql');

        $migrator = app('migrator');
        $migrator->setConnection('mysql');
        $this->assertContains(base_path('Modules/ClientMobileApp/Database/migrations'), $migrator->paths());
        $repository = $migrator->getRepository();
        $repository->createRepository();

        $names = [
            '2026_11_22_000001_create_mobile_loa_request_details_table',
            '2026_11_22_000008_create_mobile_complaints_table',
        ];
        $paths = [];
        foreach ($names as $name) {
            $repository->log($name, 1);
            $path = base_path("Modules/ClientMobileApp/Database/migrations/{$name}.php");
            $this->assertFileExists($path);
            $paths[] = $path;
        }

        $this->assertSame([], $migrator->run($paths));
        $this->assertFalse(Schema::connection('mysql')->hasTable('mobile_loa_request_details'));
        $this->assertFalse(Schema::connection('mysql')->hasTable('mobile_complaints'));
    }
}
