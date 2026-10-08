<?php

namespace Elcreator\aEvoAST\Pull;

/**
 * Looks releases up on GitHub (or anything that answers the same API) and fetches their files.
 * Network access is injected, so the tests never leave the machine.
 */
final class ReleaseClient
{
    public const TAG_PREFIX = 'index-';

    /** @var callable fn(string $url, array $headers): array{0:int,1:string} */
    private $get;
    /** @var callable fn(string $url, array $headers, string $dest, int $maxBytes): int */
    private $download;
    private string $api;

    public function __construct(
        string $api,
        private string $repo,
        private ?string $token = null,
        ?callable $get = null,
        ?callable $download = null,
    ) {
        $this->api = rtrim($api, '/');
        if (!preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $repo)) {
            throw new \InvalidArgumentException("aevoast.pull.repo must look like owner/name, got \"{$repo}\"");
        }
        if (!preg_match('#^https://#i', $this->api) && !self::isLoopback($this->api)) {
            throw new \InvalidArgumentException('aevoast.pull.api must be an https:// address');
        }
        $this->get = $get ?? [CurlTransport::class, 'get'];
        $this->download = $download ?? [CurlTransport::class, 'download'];
    }

    /** The release with this tag, or null when there is none. */
    public function releaseByTag(string $tag): ?array
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $tag)) {
            throw new \InvalidArgumentException("Not a valid release tag: {$tag}");
        }

        [$status, $body] = ($this->get)("{$this->api}/repos/{$this->repo}/releases/tags/{$tag}", $this->headers());
        if ($status === 404) {
            return null;
        }

        return $this->json($status, $body, 'looking up release ' . $tag);
    }

    /**
     * Tags of the releases that hold indexes, newest version first.
     *
     * @return string[]
     */
    public function indexTags(): array
    {
        [$status, $body] = ($this->get)("{$this->api}/repos/{$this->repo}/releases?per_page=100", $this->headers());
        $releases = $this->json($status, $body, 'listing releases');

        $tags = [];
        foreach ($releases as $r) {
            $tag = $r['tag_name'] ?? '';
            if (is_string($tag) && str_starts_with($tag, self::TAG_PREFIX) && empty($r['draft']) && empty($r['prerelease'])) {
                $tags[] = $tag;
            }
        }
        usort($tags, fn($a, $b) => version_compare(substr($b, strlen(self::TAG_PREFIX)), substr($a, strlen(self::TAG_PREFIX))));

        return $tags;
    }

    public function manifest(array $release): array
    {
        $asset = $this->asset($release, 'manifest.json');
        if ($asset === null) {
            throw new \RuntimeException('This release has no manifest.json: it is not an aEvoAST index release');
        }

        [$status, $body] = ($this->get)($this->assetUrl($asset), $this->headers(false), 1048576);
        if ($status !== 200) {
            throw new \RuntimeException("Could not download manifest.json (HTTP {$status})");
        }

        return Manifest::validate(json_decode($body, true));
    }

    /**
     * @throws \RuntimeException
     */
    public function download(array $release, string $name, string $dest, int $maxBytes): void
    {
        $asset = $this->asset($release, $name);
        if ($asset === null) {
            throw new \RuntimeException("The release has no file {$name}");
        }

        $status = ($this->download)($this->assetUrl($asset), $this->headers(false), $dest, $maxBytes);
        if ($status !== 200) {
            @unlink($dest);
            throw new \RuntimeException("Could not download {$name} (" . ($status ? "HTTP {$status}" : 'no answer, or larger than the manifest says') . ')');
        }
    }

    private function asset(array $release, string $name): ?array
    {
        foreach ($release['assets'] ?? [] as $a) {
            if (is_array($a) && ($a['name'] ?? null) === $name) {
                return $a;
            }
        }

        return null;
    }

    /** Files come from where the API says, and only from a host that belongs with the API. */
    private function assetUrl(array $asset): string
    {
        $url = (string) ($asset['browser_download_url'] ?? '');
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $apiHost = strtolower((string) parse_url($this->api, PHP_URL_HOST));

        $trusted = $apiHost === 'api.github.com' ? ['github.com'] : [$apiHost];
        $secure = str_starts_with($url, 'https://') || (self::isLoopback($url) && str_starts_with($url, 'http://'));
        if (!$secure || !in_array($host, $trusted, true)) {
            throw new \RuntimeException('Refusing to download from an unexpected address: ' . substr($url, 0, 120));
        }

        return $url;
    }

    private function headers(bool $api = true): array
    {
        $h = ['User-Agent: aEvoAST-pull', $api ? 'Accept: application/vnd.github+json' : 'Accept: application/octet-stream'];
        // The token is for the API only: files are fetched from github.com / its CDN, which do not need it.
        if ($api && $this->token) {
            $h[] = 'Authorization: Bearer ' . $this->token;
        }

        return $h;
    }

    private function json(int $status, string $body, string $doing): array
    {
        if ($status === 403 || $status === 429) {
            throw new \RuntimeException("GitHub refused the request while {$doing} (HTTP {$status}): probably its rate limit; set AST_PULL_TOKEN to a GitHub token, or try again later");
        }
        if ($status !== 200) {
            throw new \RuntimeException("GitHub answered HTTP {$status} while {$doing}");
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("GitHub's answer was not JSON while {$doing}");
        }

        return $data;
    }

    private static function isLoopback(string $url): bool
    {
        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
    }
}
