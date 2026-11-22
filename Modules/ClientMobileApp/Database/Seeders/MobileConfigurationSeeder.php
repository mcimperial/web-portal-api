<?php

namespace Modules\ClientMobileApp\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ClientMobileApp\App\Models\MobileConfiguration;

class MobileConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $configuration = MobileConfiguration::firstOrNew([
            'config_key' => 'loa_request_backend',
        ]);

        if (! $configuration->exists) {
            $configuration->config_value = 'system';
        }

        if ($configuration->api_header === null) {
            $configuration->api_header = 'CORPORATE-API-KEY';
        }

        if ($configuration->api_url === null) {
            $configuration->api_url = config('services.clientcare.base_url');
        }

        $seedKey = getenv('CLIENTCARE_SEED_API_KEY');
        if ($configuration->api_key === null && is_string($seedKey) && trim($seedKey) !== '') {
            $configuration->api_key = $seedKey;
        }

        if ($configuration->isDirty()) {
            $configuration->save();
        }

        if ($configuration->api_key === null) {
            $this->command?->warn('ClientCare API key not seeded; set CLIENTCARE_SEED_API_KEY before running this seeder.');
        }
    }
}
