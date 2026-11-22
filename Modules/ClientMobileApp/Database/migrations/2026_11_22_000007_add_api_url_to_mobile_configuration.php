<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->table('mobile_configuration', function (Blueprint $table) {
            $table->string('api_url', 2048)->nullable();
        });

        $url = config('services.clientcare.base_url');
        if (is_string($url) && trim($url) !== '') {
            DB::connection('mysql')->table('mobile_configuration')
                ->where('config_key', 'loa_request_backend')
                ->whereNull('api_url')
                ->update(['api_url' => $url, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('mobile_configuration', function (Blueprint $table) {
            $table->dropColumn('api_url');
        });
    }
};
