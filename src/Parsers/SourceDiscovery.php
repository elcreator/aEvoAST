<?php

namespace Elcreator\aEvoAST\Parsers;

class SourceDiscovery
{
    /**
     * @param  array|null  $config  the `aevoast` config; null reads it from the framework
     */
    public function __construct(private ?array $config = null)
    {
    }

    private function cfg(string $key, mixed $default): mixed
    {
        if ($this->config !== null) {
            return $this->config[$key] ?? $default;
        }

        return config("aevoast.{$key}", $default);
    }

    /**
     * Discover all parseable sources in the current Evolution CMS project.
     *
     * @param  string $basePath  Project root (where composer.json lives)
     * @return array<int, array{path: string, layer: string, name: string, version: string|null}>
     */
    public function discover(string $basePath): array
    {
        $sources = [];

        // In Evolution CMS, base_path() is the core/ directory itself (core/src/Core.php),
        // and the site root, where assets/ and core/custom live, is its parent.
        $siteRoot = $basePath;
        $coreSrc = null;
        if (is_file($basePath . '/src/Core.php')) {
            $coreSrc = $basePath . '/src';
            $siteRoot = dirname($basePath);
        }

        // Layer 0: Evolution CMS core
        $corePath = $coreSrc ?? $basePath . '/vendor/evolution-cms/evolution/core';
        if (!is_dir($corePath)) {
            $corePath = $basePath . '/core/vendor/evolution-cms/evolution';
        }
        if (!is_dir($corePath)) {
            // Try the core src directly
            $corePath = $basePath . '/core/src';
        }

        if (is_dir($corePath)) {
            $sources[] = [
                'path'    => $corePath,
                'layer'   => 'core',
                'name'    => 'evolution-cms',
                'version' => $this->getComposerVersion($basePath, 'evolution-cms/evolution')
                    ?? $this->runningCoreVersion(),
            ];
        }

        // Layer 1: Auto-discovered extras
        if ($this->cfg('auto_extras', true)) {
            $vendors = $this->cfg('extra_vendors', []);
            foreach ($vendors as $vendor) {
                $vendorPath = $this->vendorPath($basePath) . '/' . $vendor;
                if (!is_dir($vendorPath)) continue;

                foreach (new \DirectoryIterator($vendorPath) as $item) {
                    if ($item->isDot() || !$item->isDir()) continue;

                    $packageName = $vendor . '/' . $item->getFilename();
                    $sources[] = [
                        'path'    => $item->getRealPath(),
                        'layer'   => 'extra',
                        'name'    => $packageName,
                        'version' => $this->getComposerVersion($basePath, $packageName),
                    ];
                }
            }
        }

        // Layer 2: Local custom code
        $localPaths = $this->cfg('local_paths', []);
        foreach ($localPaths as $rel) {
            $absPath = $siteRoot . '/' . ltrim($rel, '/');
            if (!is_dir($absPath)) continue;

            $sources[] = [
                'path'    => $absPath,
                'layer'   => 'local',
                'name'    => 'local/' . basename($rel),
                'version' => null,
            ];
        }

        // Layer 'docs': documentation to search next to the code (a checkout of evo-docs, say).
        foreach ($this->cfg('docs_paths', []) as $docs) {
            $path = (string) ($docs['path'] ?? '');
            if ($path !== '' && !preg_match('#^([a-zA-Z]:)?[/\\\\]#', $path)) {
                $path = $siteRoot . '/' . $path;
            }
            if ($path === '' || !is_dir($path)) {
                continue;
            }

            $sources[] = [
                'path'    => $path,
                'layer'   => 'docs',
                'name'    => (string) ($docs['name'] ?? basename($path)),
                'version' => $docs['version'] ?? null,
                'lang'    => (string) ($docs['lang'] ?? 'en'),
            ];
        }

        return $sources;
    }

    /**
     * Get installed version of a composer package from composer.lock.
     */
    private function getComposerVersion(string $basePath, string $packageName): ?string
    {
        $lockFile = $basePath . '/core/composer.lock';
        if (!file_exists($lockFile)) {
            $lockFile = $basePath . '/composer.lock';
        }

        if (!file_exists($lockFile)) {
            return null;
        }

        $lock = json_decode(file_get_contents($lockFile), true);
        if (!$lock) return null;

        $packages = array_merge(
            $lock['packages'] ?? [],
            $lock['packages-dev'] ?? []
        );

        foreach ($packages as $pkg) {
            if (($pkg['name'] ?? '') === $packageName) {
                return $pkg['version'] ?? null;
            }
        }

        return null;
    }

    /** The Evolution CMS version that is running, when we are inside it. */
    private function runningCoreVersion(): ?string
    {
        try {
            if (function_exists('evo')) {
                $version = evo()->version();

                return is_string($version) && $version !== '' ? $version : null;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function vendorPath(string $basePath): string
    {
        // Evolution CMS 3.x keeps vendor inside /core/
        $corePath = $basePath . '/core/vendor';
        if (is_dir($corePath)) {
            return $corePath;
        }
        return $basePath . '/vendor';
    }
}
