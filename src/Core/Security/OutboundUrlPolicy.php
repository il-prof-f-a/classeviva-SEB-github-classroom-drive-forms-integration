<?php

declare(strict_types=1);

namespace App\Core\Security;

use RuntimeException;

final class OutboundUrlPolicy
{
    /** @var callable(string):array<string> */
    private $resolver;

    /** @param list<string> $allowedHosts @param list<int> $allowedPorts */
    public function __construct(array $allowedHosts, array $allowedPorts = [443], ?callable $resolver = null)
    {
        $this->allowedHosts = array_values(array_unique(array_map('strtolower', $allowedHosts)));
        $this->allowedPorts = array_values(array_unique(array_map('intval', $allowedPorts)));
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = [];
            foreach (dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($ip)) $ips[] = $ip;
            }
            return $ips;
        };
    }

    /** @var list<string> */
    private array $allowedHosts;
    /** @var list<int> */
    private array $allowedPorts;

    public function assertAllowed(string $url): void
    {
        $this->resolveAllowed($url);
    }

    /** @return list<string> */
    public function resolveAllowed(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || !empty($parts['user']) || !empty($parts['pass'])) {
            throw new RuntimeException('URL esterno non consentito');
        }
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = (int)($parts['port'] ?? 443);
        if ($host === '' || !in_array($host, $this->allowedHosts, true) || !in_array($port, $this->allowedPorts, true)) {
            throw new RuntimeException('Destinazione esterna non consentita');
        }
        $ips = ($this->resolver)($host);
        if ($ips === []) {
            throw new RuntimeException('Host esterno non risolvibile');
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Destinazione di rete non pubblica');
            }
        }
        return array_values(array_unique($ips));
    }
}
