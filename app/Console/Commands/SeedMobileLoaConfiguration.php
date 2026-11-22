<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\ClientMobileApp\App\Models\MobileConfiguration;

/**
 * Inserts or updates the LOA request backend row in mobile_configuration.
 *
 * Usage:
 *   php artisan mobile:seed-loa-config
 *   php artisan mobile:seed-loa-config --backend=system --key=... --url=...
 */
class SeedMobileLoaConfiguration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mobile:seed-loa-config
        {--backend=third_party : Value for config_value (third_party or system)}
        {--header=CORPORATE-API-KEY : Value for api_header}
        {--key= : API key; defaults to the CLIENTCARE_SEED_API_KEY env value}
        {--url= : API base URL; defaults to services.clientcare.base_url}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Insert or update the loa_request_backend row in mobile_configuration';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $apiKey = $this->option('key') ?: env('CLIENTCARE_SEED_API_KEY');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            $this->error('No API key provided. Pass --key= or set CLIENTCARE_SEED_API_KEY in .env.');

            return self::FAILURE;
        }

        $configuration = MobileConfiguration::updateOrCreate(
            ['config_key' => 'loa_request_backend'],
            [
                'config_value' => $this->option('backend'),
                'api_header' => $this->option('header'),
                'api_key' => trim($apiKey),
                'api_url' => $this->option('url') ?: config('services.clientcare.base_url'),
            ]
        );

        $this->info("mobile_configuration row {$configuration->id} saved:");
        $this->table(
            ['config_key', 'config_value', 'api_header', 'api_url'],
            [[
                $configuration->config_key,
                $configuration->config_value,
                $configuration->api_header,
                $configuration->api_url,
            ]]
        );

        return self::SUCCESS;
    }
}
