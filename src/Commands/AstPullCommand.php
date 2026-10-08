<?php

namespace Elcreator\aEvoAST\Commands;

use Illuminate\Console\Command;
use Elcreator\aEvoAST\Embeddings\Embedder;
use Elcreator\aEvoAST\Parsers\SourceDiscovery;
use Elcreator\aEvoAST\Pull\Manifest;
use Elcreator\aEvoAST\Pull\Puller;
use Elcreator\aEvoAST\Pull\ReleaseClient;
use Elcreator\aEvoAST\Storage\IndexMeta;

class AstPullCommand extends Command
{
    protected $signature = 'ast:pull
        {--tag= : Release to take (default: index-<your Evolution CMS version>)}
        {--docs= : Documentation languages, comma separated (default: aevoast.pull.docs_langs, "en")}
        {--no-docs : Skip the documentation}
        {--source= : Only this source, e.g. evolution-cms}
        {--merge : Run ast:merge afterwards}';

    protected $description = 'Download ready-made indexes of Evolution CMS and its documentation instead of building them';

    public function handle(Embedder $embedder, SourceDiscovery $discovery): int
    {
        $cfg = (array) config('aevoast.pull', []);
        try {
            $client = new ReleaseClient(
                (string) ($cfg['api'] ?? 'https://api.github.com'),
                (string) ($cfg['repo'] ?? 'elcreator/aEvoAST'),
                ($cfg['token'] ?? null) ?: null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $tag = $this->option('tag') ?: $this->tagForThisSite($discovery);
        if ($tag === null) {
            $this->error('Could not tell which Evolution CMS version this is. Pass --tag=index-<version>.');
            return 1;
        }

        try {
            $this->line("Looking for {$tag} in " . ($cfg['repo'] ?? 'elcreator/aEvoAST') . '…');
            $release = $client->releaseByTag($tag);
            if ($release === null) {
                return $this->noSuchRelease($client, $tag);
            }

            $manifest = $client->manifest($release);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }

        $current = IndexMeta::describe($embedder);
        $langs = $this->option('no-docs') ? [] : array_values(array_filter(array_map('trim', explode(
            ',', (string) ($this->option('docs') ?: implode(',', (array) ($cfg['docs_langs'] ?? ['en'])))
        ))));
        $plan = Manifest::select($manifest, $current, $langs, $this->option('source') ?: null);

        foreach ($plan['skipped'] as $skip) {
            $this->warn("  not taken: {$skip['source']}: {$skip['reason']}");
        }
        if ($plan['install'] === []) {
            $this->error('Nothing in this release fits your setup (embedder: ' . IndexMeta::label($current) . ').');
            $this->info('Build it yourself instead: php artisan ast:parse && php artisan ast:merge');
            return 1;
        }

        $puller = new Puller($client, (string) config('aevoast.cache_path'));
        $failed = 0;
        foreach ($puller->install($release, $plan['install'], $tag) as $r) {
            if ($r['status'] === 'failed') {
                $failed++;
                $this->error("  ✗ {$r['source']}: {$r['detail']}");
            } else {
                $this->info("  ✓ {$r['source']}: " . ($r['status'] === 'up-to-date' ? 'already up to date' : 'downloaded'));
            }
        }

        if ($failed === count($plan['install'])) {
            return 1;
        }

        if ($this->option('merge')) {
            return $this->call('ast:merge');
        }

        $this->newLine();
        $this->info('Next: php artisan ast:merge');
        $this->line('Your own code is not in a downloaded index: add it with `php artisan ast:parse --layer=local`, then merge.');

        return $failed > 0 ? 1 : 0;
    }

    private function tagForThisSite(SourceDiscovery $discovery): ?string
    {
        foreach ($discovery->discover(base_path()) as $source) {
            if ($source['layer'] === 'core' && !empty($source['version'])) {
                return ReleaseClient::TAG_PREFIX . $source['version'];
            }
        }

        return null;
    }

    private function noSuchRelease(ReleaseClient $client, string $tag): int
    {
        $this->error("There is no published index for {$tag}.");

        try {
            $tags = array_slice($client->indexTags(), 0, 5);
        } catch (\RuntimeException) {
            $tags = [];
        }

        if ($tags) {
            $this->line('Published: ' . implode(', ', $tags));
            $this->line("A neighbouring version is mostly right for a different Evolution CMS release: `php artisan ast:pull --tag={$tags[0]}`.");
        }
        $this->line('Or build your own: php artisan ast:parse && php artisan ast:merge');

        return 1;
    }
}
