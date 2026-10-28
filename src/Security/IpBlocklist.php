<?php

namespace App\Security;

use App\Audit\AuditLog;
use App\Entity\BlockedIp;
use App\Enum\AuditType;
use App\Repository\BlockedIpRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * IP addresses and ranges that can't send forms (BlockedIpListener). Admins
 * paste them in under Blocked IPs in /admin, or block the address a user
 * signed up from.
 */
class IpBlocklist implements ResetInterface
{
    /**
     * Never blockable: blocking them would turn everyone away. Private ranges are kamal-proxy
     * and Docker; the rest are Cloudflare's (https://www.cloudflare.com/ips/), in case the site
     * is ever proxied through it and visitors all seem to come from there.
     */
    public const array PROTECTED_RANGES = [
        '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7', 'fe80::/10',
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /** @var list<string>|null */
    private ?array $addresses = null;

    public function __construct(
        private readonly BlockedIpRepository $repository,
        private readonly EntityManagerInterface $manager,
        private readonly RequestStack $requests,
        private readonly AuditLog $audit,
    ) {}

    public function isBlocked(?string $ip): bool
    {
        if (null === $ip || '' === $ip) {
            return false;
        }
        $this->addresses ??= array_map(static fn (BlockedIp $blocked): string => $blocked->getAddress(), $this->repository->findAll());

        return $this->addresses !== [] && IpUtils::checkIp($ip, $this->addresses);
    }

    /**
     * Blocks every address in $text (one per line, or separated by commas or spaces; "#"
     * starts a comment). Already-blocked addresses are skipped.
     *
     * @return array{added: list<string>, skipped: list<string>, rejected: array<string, string>} rejected: address => why
     */
    public function addFromText(string $text, ?string $note = null): array
    {
        $result = ['added' => [], 'skipped' => [], 'rejected' => []];
        $text   = (string) preg_replace('/#.*$/m', '', $text);
        foreach (array_unique(preg_split('/[\s,;]+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []) as $entry) {
            $address = self::normalise($entry);
            $problem = $address === null ? 'not an IP address or range' : $this->problemWith($address);
            if ($problem !== null) {
                $result['rejected'][$entry] = $problem;
            } elseif (\in_array($address, $result['added'], true) || null !== $this->repository->findOneBy(['address' => $address])) {
                $result['skipped'][] = $address;
            } else {
                $this->manager->persist(new BlockedIp($address, $note));
                $result['added'][] = $address;
            }
        }
        $this->manager->flush();
        foreach ($result['added'] as $address) {
            $this->audit->record(AuditType::IpBlocked, $this->repository->findOneBy(['address' => $address]), $note === null ? [] : ['note' => $note]);
        }
        $this->reset();

        return $result;
    }

    /** Returns false if it wasn't blocked. */
    public function remove(string $address): bool
    {
        $blocked = $this->repository->findOneBy(['address' => self::normalise($address) ?? $address]);
        if (null === $blocked) {
            return false;
        }
        $this->audit->record(AuditType::IpUnblocked, $blocked);
        $this->manager->remove($blocked);
        $this->manager->flush();
        $this->reset();

        return true;
    }

    /** "203.0.113.7", "203.0.113.0/24" or an IPv6 address or range, tidied; null if it's none of those. */
    public static function normalise(string $entry): ?string
    {
        $parts = explode('/', trim($entry), 2);
        $ip    = filter_var($parts[0], FILTER_VALIDATE_IP);
        if (false === $ip) {
            return null;
        }
        $ip = (string) inet_ntop((string) inet_pton($ip));
        if (!isset($parts[1])) {
            return $ip;
        }
        $max = str_contains($ip, ':') ? 128 : 32;
        if (!ctype_digit($parts[1]) || (int) $parts[1] > $max) {
            return null;
        }

        return (int) $parts[1] === $max ? $ip : $ip.'/'.(int) $parts[1];
    }

    private function problemWith(string $address): ?string
    {
        $ip = explode('/', $address)[0];
        if (IpUtils::checkIp($ip, self::PROTECTED_RANGES)) {
            return 'a private or Cloudflare address: blocking it would block everyone';
        }
        if (str_contains($address, '/') && (int) explode('/', $address)[1] < (str_contains($ip, ':') ? 32 : 16)) {
            return 'too wide a range';
        }
        $mine = $this->requests->getMainRequest()?->getClientIp();
        if (null !== $mine && IpUtils::checkIp($mine, $address)) {
            return 'your own address: you’d block yourself';
        }

        return null;
    }

    public function reset(): void
    {
        $this->addresses = null;
    }
}
