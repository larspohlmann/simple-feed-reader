<?php

declare(strict_types=1);

// php ../docs/superpowers/plans/2026-09-28-1202-scripts/stale-names.php var/refactor-1202/<map>.php
// POSIX EREs for `git grep -E \( -f … \) --and --not -e '^namespace '`: one per old FQCN in either backslash spelling,
// and one per old path reference. The `--not` spares a moved file's own namespace, which can be an old FQCN
// (`…\SearchIndexReader` becomes a folder).

require __DIR__ . '/class-names.php';

/** @var array<string, string> $moves */
$moves = require $argv[1];
$patterns = [];
foreach (array_keys($moves) as $old) {
    // Segments are [A-Za-z0-9_], never special in an ERE. `\\{1,2}` is one or two backslashes, and `\\` inside a
    // bracket is a literal backslash. A name followed by a word character or a backslash is another class.
    $patterns[] = implode('\\\\{1,2}', explode('\\', $old)) . '([^[:alnum:]_\\\\]|$)';
    $path = str_starts_with($old, 'App\\Tests\\') ? pathOf($old) : substr(pathOf($old), strlen('src/'));
    $patterns[] = '(^|[^[:alnum:]_])' . str_replace('.', '\\.', $path);
}
sort($patterns);
echo implode("\n", $patterns), "\n";
