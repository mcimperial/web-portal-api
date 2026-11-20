<?php

namespace Tests\Unit;

use App\Http\Helpers\SmsHelper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsHelperTest extends TestCase
{
    public function test_it_uses_the_working_goip_defaults_when_sms_config_is_not_cached(): void
    {
        config()->offsetUnset('services.sms');
        Http::fake([
            'http://46.250.238.187/goip/sendsms/' => Http::response(['success' => true], 200),
        ]);

        $result = SmsHelper::send('09171234567', 'Test message');

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => $request->url() === 'http://46.250.238.187/goip/sendsms/');
    }

    public function test_it_posts_an_sms_to_the_configured_gateway(): void
    {
        config()->set('services.sms', [
            'enabled' => true,
            'url' => 'http://sms-gateway.test/send',
            'username' => 'gateway-user',
            'password' => 'gateway-password',
            'timeout' => 20,
        ]);

        Http::fake([
            'http://sms-gateway.test/send' => Http::response(['success' => true], 202),
        ]);

        $result = SmsHelper::send('639171234567', 'Test message');

        $this->assertTrue($result['success']);
        $this->assertSame(202, $result['http_code']);
        Http::assertSent(fn ($request) => $request->data() === [
            'auth' => [
                'username' => 'gateway-user',
                'password' => 'gateway-password',
            ],
            'provider' => 'SIMNETWORK',
            'number' => '09171234567',
            'content' => 'Test message',
        ]);
    }

    public function test_it_does_not_report_success_when_the_gateway_rejects_the_message(): void
    {
        config()->set('services.sms', [
            'enabled' => true,
            'url' => 'http://sms-gateway.test/send',
            'username' => 'gateway-user',
            'password' => 'gateway-password',
            'timeout' => 20,
        ]);
        Http::fake([
            'http://sms-gateway.test/send' => Http::response([
                'success' => false,
                'message' => 'Gateway rejected recipient.',
            ], 200),
        ]);

        $result = SmsHelper::send('09171234567', 'Test message');

        $this->assertFalse($result['success']);
        $this->assertSame('Gateway rejected recipient.', $result['error']);
    }
}
