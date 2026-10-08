<?php

namespace Elcreator\aEvoAST\Pull;

/**
 * The only place aEvoAST talks to GitHub. HTTPS only (plain http just for a loopback server, which is
 * how the tests and a local mirror run), at most five redirects, every redirect https again.
 */
final class CurlTransport
{
    /** @return array{0: int, 1: string} status (0 = no answer) and body */
    public static function get(string $url, array $headers, int $maxBytes = 1048576): array
    {
        $body = '';
        $ch = self::handle($url, $headers);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, string $chunk) use (&$body, $maxBytes) {
            $body .= $chunk;

            return strlen($body) > $maxBytes ? 0 : strlen($chunk); // 0 aborts the transfer
        });
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $body];
    }

    /**
     * Stream a file to disk, stopping once it is bigger than it may be.
     *
     * @return int HTTP status (0 = no answer or aborted)
     */
    public static function download(string $url, array $headers, string $dest, int $maxBytes): int
    {
        $fp = fopen($dest, 'wb');
        if ($fp === false) {
            return 0;
        }

        $written = 0;
        $ch = self::handle($url, $headers, 600);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, string $chunk) use ($fp, &$written, $maxBytes) {
            $written += strlen($chunk);
            if ($written > $maxBytes) {
                return 0;
            }

            return fwrite($fp, $chunk);
        });
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        return $written > $maxBytes ? 0 : $status;
    }

    private static function handle(string $url, array $headers, int $timeout = 60)
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_PROTOCOLS      => $loopback ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | ($loopback ? CURLPROTO_HTTP : 0),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        return $ch;
    }
}
