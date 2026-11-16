<?php

namespace App\Services;

final class OperationalSyncEndpoint
{
    public function isAllowed(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        if (($parts['scheme'] ?? null) === 'https') {
            return true;
        }
        $host = trim($parts['host'], '[]');

        return ($parts['scheme'] ?? null) === 'http'
            && isset($parts['port'])
            && filter_var($host, FILTER_VALIDATE_IP) !== false
            && app()->environment(['local', 'testing'])
            && config('operational_sync.allow_insecure_ip_http') === true;
    }
}
