<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        $connection = DB::connection('mysql');

        $connection->transaction(function () use ($connection) {
            $row = $connection->table('mobile_configuration')
                ->where('config_key', 'loa_request_backend')
                ->lockForUpdate()
                ->first(['id', 'api_key']);

            if (! $row || $row->api_key === null) {
                return;
            }

            $decoded = base64_decode($row->api_key, true);
            $payload = $decoded === false ? null : json_decode($decoded, true);
            if (! is_array($payload) || ! isset($payload['iv'], $payload['value'], $payload['mac'])) {
                return;
            }

            $connection->table('mobile_configuration')->where('id', $row->id)->update([
                'api_key' => Crypt::decryptString($row->api_key),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('The plaintext API key migration cannot be rolled back automatically.');
    }
};
