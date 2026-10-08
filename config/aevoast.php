<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ollama Embedding Settings
    |--------------------------------------------------------------------------
    */

    // Which embedder builds and queries the index: 'ollama' (local, default) or 'openai'
    // (any OpenAI-compatible /embeddings endpoint). Vectors of different models are not
    // comparable: switching means re-running ast:parse --force.
    'embedder' => env('AST_EMBEDDER', 'ollama'),

    // Remote embedders never receive the 'local' layer (your own code) unless this is true.
    'allow_remote_local' => (bool) env('AST_ALLOW_REMOTE_LOCAL', false),

    'openai' => [
        'url'        => env('AST_OPENAI_URL', 'https://api.openai.com/v1'),
        'key'        => env('AST_OPENAI_KEY'),
        'model'      => env('AST_OPENAI_MODEL', 'text-embedding-3-small'),
        'dimensions' => env('AST_OPENAI_DIMENSIONS'), // optional, text-embedding-3-* only
    ],

    'ollama' => [
        'url'   => env('AST_OLLAMA_URL', 'http://localhost:11434'),
        // bge-m3 is multilingual (the docs are half Russian) and found the right method/page far
        // more often than nomic-embed-text in our measurements (see README, "Measuring quality").
        // nomic-embed-text is the lighter choice: 274 MB instead of 1.2 GB, English only.
        'model' => env('AST_OLLAMA_MODEL', 'bge-m3'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Output Settings
    |--------------------------------------------------------------------------
    */

    // How the keyword ranking and the vector ranking are weighed when they are fused. Tuned on a
    // small gold set with bge-m3 (keywords at 0.3 beat 1.0 on both code and docs); a weaker
    // embedder may want more weight on keywords, so rerun eval/run.php if you change the model.
    'search' => [
        'vector_weight'  => (float) env('AST_VECTOR_WEIGHT', 1.0),
        'lexical_weight' => (float) env('AST_LEXICAL_WEIGHT', 0.3),
    ],

    'output' => [
        // Where to store generated files relative to project root
        'path'   => env('AST_OUTPUT_PATH', '.ast'),
        // Index format: 'bin' (compact and quick, the default), or 'json' / 'csv' to read it by eye.
        // Symbol maps are always JSON (or CSV with 'csv'); only the chunks and vectors go binary.
        'format' => env('AST_OUTPUT_FORMAT', 'bin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    |
    | Pre-parsed symbol maps and embeddings for core and extras are cached
    | here so they're only regenerated when the package version changes.
    |
    */

    'cache_path' => env('AST_CACHE_PATH', storage_path('ast-cache')),

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | Define which directories belong to which layer.
    | layer: 'core' | 'extra' | 'local'
    |
    | 'auto_extras' => true will auto-discover installed composer packages
    | under evolution-cms-extras/* and other known Evo extra vendors.
    |
    */

    'auto_extras' => true,

    'extra_vendors' => [
        'evolution-cms-extras',
        'seiger',
    ],

    'local_paths' => [
        'assets/snippets',
        'assets/plugins',
        'assets/modules',
        'core/custom',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ready-made indexes (ast:pull)
    |--------------------------------------------------------------------------
    |
    | Download the index of Evolution CMS and its documentation from a GitHub release instead
    | of building it. 'token' is optional (a higher rate limit) and is sent to the API only.
    |
    */

    'pull' => [
        'repo'       => env('AST_PULL_REPO', 'elcreator/aEvoAST'),
        'api'        => env('AST_PULL_API', 'https://api.github.com'),
        'token'      => env('AST_PULL_TOKEN'),
        'docs_langs' => ['en'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentation
    |--------------------------------------------------------------------------
    |
    | Markdown documentation indexed next to the code, so "how do I ..." questions
    | find the page as well as the method. Paths are absolute or relative to the site
    | root. One language per entry; the docs repository keeps en/, ru/ and ua/ apart.
    |
    |   ['name' => 'evo-docs', 'path' => '/path/to/evo-docs/en', 'lang' => 'en'],
    |
    */

    'docs_paths' => [],

    /*
    |--------------------------------------------------------------------------
    | Embedding Batch Size
    |--------------------------------------------------------------------------
    |
    | How many chunks to send to Ollama in one request.
    | nomic-embed-text supports batched input via the /api/embed endpoint.
    |
    */

    'batch_size' => 32,

    /*
    |--------------------------------------------------------------------------
    | Chunking Strategy
    |--------------------------------------------------------------------------
    |
    | 'method'   — one chunk per method (best precision)
    | 'class'    — one chunk per class (fewer embeddings, less precise)
    | 'file'     — one chunk per file (fewest embeddings)
    |
    */

    'chunk_by' => 'method',
];
