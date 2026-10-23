<?php

namespace App\Tests\Controller;

use App\Entity\AuditEvent;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Behind kamal-proxy (a private address), the visitor's IP comes from
 * X-Forwarded-For; seen here through the IP a failed login records.
 */
final class TrustedProxyTest extends WebTestCase
{
    use DatabaseTestTrait;

    /** Visitors use real public addresses: private_ranges also covers the documentation ones (203.0.113.0/24...). */
    private const string PROXY = '172.18.0.5';

    protected function setUp(): void
    {
        $this->bootClient();
    }

    public function testVisitorIpAndHttpsComeFromTheProxy(): void
    {
        $this->failLogin(['REMOTE_ADDR' => self::PROXY, 'HTTP_X_FORWARDED_FOR' => '8.8.4.4', 'HTTP_X_FORWARDED_PROTO' => 'https']);

        self::assertSame('8.8.4.4', $this->lastIp());
        self::assertStringStartsWith('https://', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testAddressesTheVisitorSendsThemselvesAreIgnored(): void
    {
        // The visitor sent "X-Forwarded-For: 6.6.6.6"; the proxy added their real address after it.
        $this->failLogin(['REMOTE_ADDR' => self::PROXY, 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 8.8.4.4']);

        self::assertSame('8.8.4.4', $this->lastIp());
    }

    public function testHeadersFromPublicAddressesAreNotTrusted(): void
    {
        $this->failLogin(['REMOTE_ADDR' => '1.1.1.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6']);

        self::assertSame('1.1.1.1', $this->lastIp());
    }

    /** @param array<string, string> $server */
    private function failLogin(array $server): void
    {
        $this->client->request('GET', '/login', server: $server);
        $this->client->submitForm('Log in', ['email' => 'sam@example.com', 'password' => 'wrong'], serverParameters: $server);
    }

    private function lastIp(): ?string
    {
        return $this->manager->getRepository(AuditEvent::class)->findOneBy([], ['id' => 'DESC'])?->getIp();
    }
}
