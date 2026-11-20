<?php

namespace Tests\Unit;

use App\Http\Helpers\SmsHelper;
use ReflectionClass;
use Tests\TestCase;

class SmsHelperTest extends TestCase
{
    public function test_it_normalizes_supported_philippine_mobile_formats(): void
    {
        $method = (new ReflectionClass(SmsHelper::class))->getMethod('cleanMobileNumber');

        $this->assertSame('09171234567', $method->invoke(null, '0917 123 4567'));
        $this->assertSame('09171234567', $method->invoke(null, '639171234567'));
        $this->assertSame('09171234567', $method->invoke(null, '9171234567'));
        $this->assertNull($method->invoke(null, '12345'));
    }

    public function test_it_detects_structured_gateway_failure_responses(): void
    {
        $method = (new ReflectionClass(SmsHelper::class))->getMethod('responseIndicatesFailure');

        $this->assertTrue($method->invoke(null, ['success' => false]));
        $this->assertTrue($method->invoke(null, ['status' => 'failed']));
        $this->assertFalse($method->invoke(null, ['success' => true]));
        $this->assertFalse($method->invoke(null, ['taskId' => '123']));
    }
}
