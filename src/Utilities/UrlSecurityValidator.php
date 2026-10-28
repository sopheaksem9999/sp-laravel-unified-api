<?php

declare(strict_types=1);

namespace Sopheak\Core\Utilities;

class UrlSecurityValidator
{
    /**
     * Determines whether the given URL is a valid, publicly routable HTTP or HTTPS URL.
     * Rejects private, reserved, loopback, and cloud metadata IP ranges.
     */
    public static function isPublicHttpUrl(string $url, ?bool $allowPrivate = null): bool
    {
        if ($allowPrivate === null) {
            $allowPrivate = self::isPrivateAllowedByConfig();
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = trim($parts['host'], '[]');
        if ($host === '') {
            return false;
        }

        $lowerHost = strtolower($host);

        // Reject localhost and local development domains
        if (!$allowPrivate && ($lowerHost === 'localhost' || str_ends_with($lowerHost, '.localhost') || str_ends_with($lowerHost, '.local') || str_ends_with($lowerHost, '.internal'))) {
            return false;
        }

        // Check if host is a literal IP address
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $allowPrivate || self::isPublicIp($host);
        }

        // If private is allowed, valid http(s) host is sufficient
        if ($allowPrivate) {
            return true;
        }

        // Host is a domain name; resolve DNS to check target IP addresses
        $ips = @gethostbynamel($host);
        if ($ips === false || empty($ips)) {
            // If DNS resolution returned no IPs, reject for safety
            return false;
        }

        foreach ($ips as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if an IP is publicly routable and not private, reserved, or loopback.
     */
    public static function isPublicIp(string $ip): bool
    {
        // Reject 0.0.0.0 and IPv6 loopback / unspecified
        if ($ip === '0.0.0.0' || $ip === '::' || $ip === '::1') {
            return false;
        }

        // Reject IPv4 link-local (169.254.0.0/16) AWS/GCP/Azure cloud metadata
        if (str_starts_with($ip, '169.254.')) {
            return false;
        }

        // Validate IP against private and reserved ranges
        $isValid = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($isValid === false) {
            return false;
        }

        return true;
    }

    private static function isPrivateAllowedByConfig(): bool
    {
        try {
            if (function_exists('config') && function_exists('app') && app()->has('config')) {
                return (bool) config('webhooks.allow_private_ips', false);
            }
        } catch (\Throwable) {
            // Container not bound or during early bootstrap
        }

        return false;
    }
}
