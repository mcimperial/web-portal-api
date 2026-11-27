<?php

namespace Tests\Unit;

use Modules\ClientThirdParty\App\Models\ApiCredential;
use PHPUnit\Framework\TestCase;

class ApiCredentialIpWhitelistTest extends TestCase
{
    public function test_exact_ip_addresses_are_allowed(): void
    {
        $credential = new ApiCredential([
            'allowed_ips' => ['8.156.24.10', '2001:db8::1'],
        ]);

        $this->assertTrue($credential->allowsIp('8.156.24.10'));
        $this->assertTrue($credential->allowsIp('2001:db8::1'));
        $this->assertFalse($credential->allowsIp('8.156.24.11'));
    }

    public function test_abbreviated_ipv4_range_includes_both_endpoints(): void
    {
        $credential = new ApiCredential([
            'allowed_ips' => ['8.156.24.0-255'],
        ]);

        $this->assertTrue($credential->allowsIp('8.156.24.0'));
        $this->assertTrue($credential->allowsIp('8.156.24.127'));
        $this->assertTrue($credential->allowsIp('8.156.24.255'));
        $this->assertFalse($credential->allowsIp('8.156.23.255'));
        $this->assertFalse($credential->allowsIp('8.156.25.0'));
    }

    public function test_full_ipv4_range_is_supported(): void
    {
        $credential = new ApiCredential([
            'allowed_ips' => ['8.156.24.240-8.156.25.10'],
        ]);

        $this->assertTrue($credential->allowsIp('8.156.24.255'));
        $this->assertTrue($credential->allowsIp('8.156.25.10'));
        $this->assertFalse($credential->allowsIp('8.156.25.11'));
    }

    public function test_ipv4_cidr_blocks_include_their_full_address_range(): void
    {
        $credential = new ApiCredential([
            'allowed_ips' => ['163.116.128.0/17', '162.10.0.0/17'],
        ]);

        $this->assertTrue($credential->allowsIp('163.116.128.0'));
        $this->assertTrue($credential->allowsIp('163.116.255.255'));
        $this->assertFalse($credential->allowsIp('163.116.127.255'));
        $this->assertFalse($credential->allowsIp('163.117.0.0'));

        $this->assertTrue($credential->allowsIp('162.10.0.0'));
        $this->assertTrue($credential->allowsIp('162.10.127.255'));
        $this->assertFalse($credential->allowsIp('162.9.255.255'));
        $this->assertFalse($credential->allowsIp('162.10.128.0'));
    }

    public function test_invalid_and_reversed_ranges_are_rejected(): void
    {
        $this->assertTrue(ApiCredential::isValidIpRestriction('8.156.24.0-255'));
        $this->assertTrue(ApiCredential::isValidIpRestriction('8.156.24.240-8.156.25.10'));
        $this->assertTrue(ApiCredential::isValidIpRestriction('163.116.128.0/17'));
        $this->assertFalse(ApiCredential::isValidIpRestriction('163.116.128.0/33'));
        $this->assertFalse(ApiCredential::isValidIpRestriction('163.116.128.0/-1'));
        $this->assertFalse(ApiCredential::isValidIpRestriction('163.116.128.0/17/24'));
        $this->assertFalse(ApiCredential::isValidIpRestriction('8.156.24.255-1'));
        $this->assertFalse(ApiCredential::isValidIpRestriction('8.156.24.0-256'));
        $this->assertFalse(ApiCredential::isValidIpRestriction('not-an-ip'));
    }
}
