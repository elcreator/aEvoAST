<?php

namespace Elcreator\aEvoAST\Embeddings;

/**
 * Decides whether an endpoint is on this machine / private network. Used to keep
 * project code from being sent to a third party by accident.
 */
final class HostCheck
{
    public static function isPrivate(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $host = strtolower(trim($host, '[]'));
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false; // a public hostname
        }

        if ($host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
