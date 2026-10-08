# elcreator/aEvoAST

**Ask your Evolution CMS project a question in plain English and get the real methods and documentation pages that answer it.**

```bash
php artisan ast:search "check if the manager user has a permission"
```

```
#1 [core:evolution-cms]
   EvolutionCMS\Core::hasPermission($pm, $context = …) [public] - Returns 1 if user has the currect permission
   core/src/Core.php
```

It indexes Evolution CMS itself, the extras you installed, and your own snippets, plugins and modules, and (optionally) the documentation. You don't need to know AI to use it: under the hood it is a search engine over your code, and it works fully offline.

## Who is this for?

- **You remember what you want, not what it is called.** "How do I run a snippet from PHP?" `grep` finds nothing; `ast:search` finds `Core::runSnippet`.
- **You use an AI assistant** (Claude, ChatGPT, Cursor, Copilot...) **to write Evo code.** Assistants guess Evo's API from memory and invent methods that don't exist. Give them real signatures from *your* version, and they stop guessing. See [Using it with an AI assistant](#using-it-with-an-ai-assistant).
- **You override core in your own code** and want to see what you changed and where.

## Quick start (about 10 minutes)

You need Evolution CMS 3.3+ and PHP 8.2+.

**1. Install**

```bash
cd core
composer require elcreator/aevoast
```

**2. Install Ollama (optional, recommended)**

[Ollama](https://ollama.com/) runs a small AI model *on your computer* that understands what a question means, so a question that doesn't repeat the method's name can still find it. Nothing leaves your machine. Install it, then:

```bash
ollama pull bge-m3
```

`bge-m3` is a 1.2 GB download. It is the default because it understands Russian as well as English (half of the Evolution CMS docs are still Russian) and found the right answer far more often than the lighter `nomic-embed-text` in our [measurements](#measuring-quality). Short on disk or RAM? `ollama pull nomic-embed-text` and set `AST_OLLAMA_MODEL=nomic-embed-text` (English only, 274 MB).

No Ollama? Skip this step. Everything still works with keyword search, only a little less clever ([details](#no-ollama-keyword-search-only)).

**3. Get the index.** Evolution CMS itself is the same for everybody, so you don't have to analyse it yourself: download the ready-made index (about 22 MB with the English documentation), then add your own code.

```bash
php artisan ast:pull                      # Evolution CMS and its documentation, for your version
php artisan ast:parse --layer=local       # your own snippets, plugins and modules
php artisan ast:merge
```

`ast:pull` looks for a published index matching your Evolution CMS version and your embedding model (see [Download instead of building](#download-instead-of-building)). If there is none, or you'd rather build it yourself:

```bash
php artisan ast:parse                     # a few minutes the first time, longer with the documentation
php artisan ast:merge
```

`ast:parse` reads the code and turns every class and method into a searchable entry. `ast:merge` puts core, extras, your code and the documentation into one index, `core/.ast/`.

**4. Ask**

```bash
php artisan ast:search "run a snippet by name from PHP"      # finds Core::runSnippet
php artisan ast:search "clear the cache"                      # code and the documentation page
php artisan ast:search "send an email"
```

That's it. Add `--top=20` for more results, `--layer=local` to look only at your own code.

## Reading the results

```
#1 (vector 0.661, keywords 17.16) [core:evolution-cms]
   EvolutionCMS\Core::hasPermission($pm, $context = …) [public] - Returns 1 if user has the currect permission
   core/src/Core.php
```

- `EvolutionCMS\Core::hasPermission(...)` is the method, with its parameters (`= …` means optional) and the one-line description from its docblock.
- `[core:evolution-cms]` is where it lives: `core`, an `extra` (like `seiger/slang`), `local` (your code) or `docs`.
- The two numbers are how strongly the *meaning* (`vector`) and the *words* (`keywords`) matched. Don't compare them across searches; they only order the results of one search.
- The last line is the file.

**Results are suggestions, not answers.** The best match is sometimes #3, and sometimes a question simply isn't in the index. Skim the first few, then open the file. Try rephrasing with the words the method would use: "delete cache" and "clear the cache" can rank differently.

## Add the documentation

Code tells you *what exists*; the docs tell you *how to use it*. Clone the [Evolution CMS documentation](https://github.com/evolution-cms/docs) next to your project, then either index it once:

```bash
git clone https://github.com/evolution-cms/docs
php artisan ast:parse --path=/path/to/docs/en --layer=docs --name=evo-docs --lang=en
php artisan ast:merge
```

or list it in `config/aevoast.php` so a plain `ast:parse` always includes it:

```php
'docs_paths' => [
    ['name' => 'evo-docs', 'path' => '/path/to/docs/en', 'lang' => 'en'],
],
```

Search results then come as two lists, **Code** and **Documentation**, so a page of prose never pushes the method you need off the screen. The docs repository keeps one folder per language: add one entry per language you want searched.

**Heads-up: the `en/` folder is not fully English yet.** When this was measured (docs commit `83d4310`, February 2026), about half of its sections (992 of 2,083) were still written in Russian, including much of the developer and extras documentation. The `ru/` and `ua/` folders are more complete. So aEvoAST detects the language of every section itself instead of trusting the folder name, and marks results that are not English:

```
#2 [docs:evo-docs in RU]
   Developers > API > runSnippet
   ...
```

- `--lang=en` shows only English documentation (code is always searched): `php artisan ast:search "clear the cache" --lang=en`
- If you read Russian or Ukrainian, index those folders too, with their own entries in `docs_paths`.
- English questions find Russian pages thanks to the multilingual default model (`bge-m3`). With the English-only `nomic-embed-text` they mostly don't: it found the right page in the top 5 for 4 of 20 test questions, `bge-m3` for 15. Keyword search can't bridge languages at all (it still matches identifiers and code such as `clearCache`).

## Keep it up to date

| You did | Run |
|---|---|
| Wrote or changed your own code | `php artisan ast:parse --layer=local` then `ast:merge` |
| Updated Evolution CMS | `php artisan ast:pull` (the new version's index) then `ast:merge`, or `ast:parse` then `ast:merge` to build it |
| Updated an extra (`composer update`) | `php artisan ast:parse` then `ast:merge` (only changed versions are re-read) |
| Switched embedding model | `php artisan ast:parse --force` then `ast:merge` |
| Not sure what state things are in | `php artisan ast:status` |

## Download instead of building

Building the index of Evolution CMS means embedding a few thousand methods and, with the docs, a few thousand more sections: minutes of work on a fast machine with Ollama, which not everyone has. The same index is published once per Evolution CMS release as a GitHub release (`index-3.5.9`), and `ast:pull` fetches it:

```bash
php artisan ast:pull                    # the index for your Evolution CMS version, plus the English docs
php artisan ast:pull --docs=en,ru       # more documentation languages
php artisan ast:pull --no-docs          # code only
php artisan ast:pull --tag=index-3.5.8  # a specific release (a neighbouring version is mostly right)
php artisan ast:pull --merge            # and run ast:merge straight away
```

What you need to know:

- **It fits your setup or it isn't taken.** An index only works with the model that made it. The published ones use `bge-m3`; if you've configured another model, `ast:pull` says so and takes nothing, and you build with `ast:parse`.
- **Nothing is trusted until checked.** Every file's size and SHA-256 are compared with the release's `manifest.json`, then the file itself is validated (right model, right number of vectors, not truncated) before it goes into the cache. A file that fails is not installed.
- **Your own code is never in it.** Add it with `ast:parse --layer=local`, as in the quick start. Nothing about your project is uploaded by any of this.
- **It is a plain cache.** The downloaded files sit in `core/storage/ast-cache/` named exactly as `ast:parse` would name them, so `ast:parse` treats them as up to date and `ast:merge` picks them up. Running `ast:pull` again downloads only what changed.
- Behind a rate limit or a private mirror? `AST_PULL_TOKEN` (a GitHub token, sent to the API only), `AST_PULL_REPO` and `AST_PULL_API` change where it looks.

If you see `There is no published index for index-<version>`, nobody has published one for your exact version yet; the message lists the ones that exist.

## Using it with an AI assistant

aEvoAST is not an AI and sends nothing anywhere. It is the *lookup* that makes an assistant's answers correct. Three ways to use it, easiest first.

**1. Copy-paste.** Search, then paste the relevant lines into your question:

```bash
php artisan ast:search "get template variable values of a document" --top=5
```

> *Using these Evolution CMS methods: `Core::getTemplateVars($idnames, $fields, $docid, ...)`, `Core::getTemplateVarOutput(...)`. Write a snippet that prints the "price" TV of document 42.*

**2. Let the assistant run it.** Assistants that work in your terminal (Claude Code, Codex, Cursor's agent...) can run `ast:search` themselves. Tell them once, in the instruction file they read (`CLAUDE.md`, `AGENTS.md`, `.cursorrules`...):

```markdown
## Evolution CMS API
Before using any Evolution CMS method, class or event, look it up instead of guessing:
`cd core && php artisan ast:search "<what you need, in plain English>"`
Use only signatures that appear in the results. Documentation hits explain usage.
```

**3. Give it the whole map.** `core/.ast/merged.symbols.json` lists every class and method (signatures only, no bodies). It is small enough to attach to a chat or to put in an assistant's context as a reference.

Your own code overriding core? The index keeps both: the winner is shown, the overridden version is hidden unless you pass `--include-overridden`. See [Layers](#layers).

## No Ollama: keyword search only

Without Ollama, the index still works. Build it without embeddings:

```bash
php artisan ast:parse --no-embeddings
php artisan ast:merge
php artisan ast:search "send an email" --mode=lexical
```

Keyword search splits names into words (`clearCache` becomes "clear cache") and matches them against your question and each method's docblock summary:

```
#1 [core:evolution-cms]  EvolutionCMS\ManagerTheme::sendRepairMail($email, $hash, $mode) ...
#2 [core:evolution-cms]  EvolutionCMS\Mail::Send() [public] - Create a message and send it.
```

It misses questions that share no words with the method or its description, which the AI model can still catch. If you add Ollama later, run `ast:parse` again: the keyword-only cache is replaced.

## Common problems

| You see | What it means |
|---|---|
| `No sources found` | Run it from the Evo `core` folder. Also check `config/aevoast.php` (`local_paths`, `docs_paths`). |
| `ollama embedder is not available` | Ollama isn't running or the model isn't pulled: `ollama pull bge-m3`. The tool continues with keyword search. |
| `The index was built with X but the configured embedder is Y` | An index only works with the model that made it. Either set the config back, or rebuild: `php artisan ast:parse --force && php artisan ast:merge`. |
| `Embeddings file not found` | Run `ast:pull` (or `ast:parse`) and then `ast:merge` first. |
| `There is no published index for index-...` | Nobody has published an index for your exact Evolution CMS version. Try a neighbouring one with `--tag=`, or build with `ast:parse`. |
| `... does not match its checksum: not installed` | The download was damaged or tampered with. Run `ast:pull` again; if it keeps happening, build locally. |
| `Corrupt index ...` | An index file is cut off or damaged. `ast:parse --force` rebuilds it, `ast:pull` fetches it again. |
| `GitHub refused the request ... rate limit` | Set `AST_PULL_TOKEN` to a GitHub token (no scopes needed), or wait. |
| Results look unrelated | Rephrase using the words a method would use. Check `ast:status` that the source you expect is listed. |
| Out of memory on a large project | Give PHP more: `php -d memory_limit=1G artisan ast:parse`. Or skip extras you don't need (`extra_vendors`) / parse one source at a time with `--source=`. |

## Privacy

By default everything runs on your computer. If you point aEvoAST at a *remote* embedding service (see [Embedders](#embedders)), your **own code (layer `local`) is never sent** unless you explicitly set `AST_ALLOW_REMOTE_LOCAL=true`. Open-source core, extras and docs may be sent.

---

## Reference

### How it works

1. **Parse**: `nikic/php-parser` extracts class, method and function signatures plus the first sentence of each docblock (no bodies).
2. **Chunk**: one entry per method (or per class/file via `chunk_by`); documentation is cut by heading.
3. **Embed** *(optional)*: each chunk becomes a vector that captures its meaning.
4. **Merge**: core, extras, your code and docs go into one index, with override tracking.
5. **Search**: keywords (BM25 over the words in names and summaries) and vectors (cosine similarity) are ranked separately and fused.

`--mode=hybrid` (default) uses both, `vector` or `lexical` only one. Signatures alone embed poorly and names alone miss paraphrases, so the fusion is better than either.

### Measuring quality

20 questions about Evo's own API ("clear the cache", "fire a plugin event", ...), each with the method or documentation page that answers it (`eval/queries.json`, `eval/run.php`). Evolution CMS 3.5.9 core and the docs repository at commit `83d4310`.

| Code (2,709 methods) | right method in top 5 | in top 10 | mean reciprocal rank |
|---|---|---|---|
| keywords only | 9 | 13 | 0.37 |
| `nomic-embed-text` vectors only | 9 | 12 | 0.27 |
| `bge-m3` vectors only | 15 | 16 | 0.38 |
| **`bge-m3` + keywords (default)** | 14 | **17** | **0.52** |

| Documentation (2,083 sections, half Russian) | right page in top 5 | in top 10 | MRR |
|---|---|---|---|
| keywords only | 9 | 9 | 0.35 |
| `nomic-embed-text` vectors only | 4 | 4 | 0.15 |
| **`bge-m3` vectors only** | **15** | 16 | **0.61** |
| `bge-m3` + keywords (default) | 12 | 16 | 0.52 |

What to take from it: the model matters most; on top of a good model, keywords add the exact-name matches vectors blur (code), but only help documentation a little, so for docs `--mode=vector` can be the better choice. The set is small (20 questions) and written by the maintainers, so read the table as a direction, not a score. On documentation a page counts as right if it mentions the method's name; pages that explain the topic without naming it are marked wrong, which understates every row.

### Commands

| Command | What it does |
|---|---|
| `ast:pull` | Download the ready-made index of Evolution CMS and its docs. `--tag=`, `--docs=en,ru`, `--no-docs`, `--source=`, `--merge` |
| `ast:parse` | Read sources into symbols and chunks (+ embeddings). `--source=`, `--layer=`, `--path= --name=`, `--lang=`, `--no-embeddings`, `--force`, `--format=bin\|json\|csv` |
| `ast:merge` | Combine all cached sources into `core/.ast/`. `--active-only`, `--output=`, `--format=bin\|json\|csv` |
| `ast:search "<question>"` | Search the merged index. `--top=`, `--mode=hybrid\|vector\|lexical`, `--layer=`, `--lang=`, `--source=`, `--include-overridden`, `--input=` |
| `ast:status` | Embedder, discovered sources, cache and index state |

### Layers

| Layer | Priority | What |
|---|---|---|
| `core` | 0 (lowest) | Evolution CMS itself |
| `extra` | 1 | Installed packages (`seiger/*`, `evolution-cms-extras/*`) |
| `local` | 2 (highest) | Your snippets, plugins, modules, `core/custom` |
| `docs` | n/a | Documentation sections (never overrides code) |

When the same class or method exists in several layers, the **highest layer wins**; lower versions are kept and marked `overridden`, so you (and an assistant) can see what you changed.

### Output files

```
core/storage/ast-cache/                      # per-source cache
  evolution-cms_3.5.9.symbols.json           # compact symbol map (readable)
  evolution-cms_3.5.9.embeddings.bin         # chunks + vectors, binary
  evo-docs.embeddings.bin                    # the documentation
  pulled.json                                # what ast:pull installed, from which release
core/.ast/                                   # merged project index
  merged.symbols.json
  merged.embeddings.bin
```

### The index file

`*.embeddings.bin` is a small binary format: a header (model, vector size, counts), the chunks (compressed JSON lines), and the vectors as raw 32-bit floats. Compared with the same index as JSON it is about a fifth of the size (the Evolution CMS core with 1,024-number vectors: 11 MB against 58 MB) and several times quicker to read (measured: 4,800 chunks in 0.6 s, against 1.1 s for only 2,700 chunks of JSON). Either way it is read one chunk at a time, so a search stays around 20 MB of PHP memory however large the index. The header says how long every part is, and a file whose parts don't add up is refused, since these files can come from a download.

Want to look inside? Build with `--format=json` (one chunk per line, readable) or read the symbol maps, which are always JSON. Set `AST_OUTPUT_FORMAT` to change the default.

### Configuration

Publish and edit `config/aevoast.php`:

```bash
php artisan vendor:publish --provider="Elcreator\aEvoAST\aEvoASTServiceProvider"
```

```php
return [
    'embedder'      => env('AST_EMBEDDER', 'ollama'),   // 'ollama' or 'openai'
    'ollama'        => ['url' => 'http://localhost:11434', 'model' => 'bge-m3'],
    'search'        => ['vector_weight' => 1.0, 'lexical_weight' => 0.3],   // how hybrid search weighs the two
    'output'        => ['path' => '.ast', 'format' => 'bin'],       // 'bin' (default), 'json' or 'csv'
    'pull'          => ['repo' => 'elcreator/aEvoAST', 'docs_langs' => ['en']],   // ast:pull
    'auto_extras'   => true,                  // find installed extras
    'extra_vendors' => ['evolution-cms-extras', 'seiger'],
    'local_paths'   => ['assets/snippets', 'assets/plugins', 'assets/modules', 'core/custom'],
    'docs_paths'    => [],                    // see "Add the documentation"
    'chunk_by'      => 'method',              // 'method', 'class' or 'file'
];
```

### Embedders

Ollama is the default. Any OpenAI-compatible `/embeddings` endpoint (OpenAI, a gateway, OpenRouter, LM Studio, llama.cpp) works too, for machines that can't run Ollama:

```env
AST_EMBEDDER=openai
AST_OPENAI_URL=https://api.openai.com/v1
AST_OPENAI_KEY=sk-...
AST_OPENAI_MODEL=text-embedding-3-small
```

- Vectors from different models can't be compared. Each embeddings file records its model; `ast:parse` rebuilds a cache made by another model, `ast:merge` refuses to mix models, `ast:search` refuses to query an index with a different one.
- A remote endpoint never receives the `local` layer unless `AST_ALLOW_REMOTE_LOCAL=true`.
- `vendor/` and `node_modules/` folders inside a source are skipped.

### Running the measurement yourself

`eval/run.php` reports hit@1/5/10 and MRR for vector, keyword and hybrid search, with and without documentation (`--model=bge-m3`, `--only=docs`, `--prefix=0`):

```bash
php eval/run.php --code=core/src --docs=/path/to/docs/en --ollama=http://localhost:11434
```

It needs Ollama and a few minutes the first time (vectors are cached). Change the chunker or the model, run it again, and only then believe the change helped.

### Publishing an index (maintainers)

The indexes `ast:pull` downloads are GitHub releases tagged `index-<Evolution CMS version>`, each holding a `manifest.json` (every file with its size and SHA-256, the model and the chunk version) and, per source, a symbol map and a binary index.

- **By workflow.** `.github/workflows/publish-index.yml` builds and publishes one: daily (it does nothing unless Evolution CMS has a release without an index), by hand (Actions > Publish index > Run workflow, with an optional version and documentation languages), and on a `repository_dispatch` of type `evolution-release`, which the Evolution CMS release workflow can send: `gh api repos/elcreator/aEvoAST/dispatches -f event_type=evolution-release -f "client_payload[ref]=$TAG"` (with a token allowed to write to this repository). It installs Ollama and `bge-m3` on the runner and embeds on a CPU, so allow it a few hours. **The workflow has not run yet**: the first manual run is its test, and until a release exists, `ast:pull` answers "no published index".
- **By hand.** With Ollama and the model on your machine: `php bin/build-release.php --evolution=/path/to/evolution --docs=/path/to/docs --langs=en --out=dist`, then attach everything in `dist/` to a release called `index-<version>`. The script prints the tag.
- The manifest is checked by `ast:pull` before any file is downloaded; bump `IndexMeta::CHUNK_VERSION` whenever the text or metadata of a chunk changes, so users don't pull indexes that no longer match what `ast:parse` would build.

### Development

```bash
composer install
composer test
```

## License

MIT
