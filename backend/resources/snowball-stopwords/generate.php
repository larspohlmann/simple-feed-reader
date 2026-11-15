<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

const COMMIT = 'dc14fb462784e5bdf90f3210022b0f91d3881ffd';
const URL = 'https://raw.githubusercontent.com/snowballstem/snowball-website/%s/algorithms/%s/stop.txt';
const LISTS = [
    'de' => ['german', '22a7785b53860256280fba74b93d8f09a0f3a8f52edaa3cdc3750e8c13a3874c'],
    'en' => ['english', '06b8b14bbe6f6e0b64c0830813819416d96a71bec1655668d83b3e602a651db6'],
    'fr' => ['french', 'e235f5e633bf831c601ce6f1dc87d8608c038209a7aab64e69a9e70f52f83d4c'],
    'es' => ['spanish', '042669c6c97605f5e57278b67474c65d58d628a59a004e618a0ac06bc2d38222'],
];
const WORD = '/^\p{L}+$/u';

function fetchedList(string $name, string $sha256): string
{
    $url = sprintf(URL, COMMIT, $name);
    $body = file_get_contents($url);
    if ($body === false) {
        throw new RuntimeException('Cannot fetch ' . $url);
    }
    if (hash('sha256', $body) !== $sha256) {
        throw new RuntimeException('SHA-256 mismatch for ' . $url);
    }

    return $body;
}

function casefolded(string $word): string
{
    $folded = mb_convert_case($word, MB_CASE_FOLD, 'UTF-8');
    $composed = Normalizer::normalize($folded, Normalizer::FORM_KC);

    return is_string($composed) ? $composed : $folded;
}

/** @return list<string> */
function wordsOf(string $body): array
{
    $words = [];
    foreach (explode("\n", $body) as $line) {
        $tokens = preg_split('/\s+/u', trim(explode('|', $line, 2)[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens === []) {
            continue;
        }
        $word = casefolded($tokens[0]);
        if (preg_match(WORD, $word) === 1) {
            $words[$word] = true;
        }
    }
    $sorted = array_keys($words);
    sort($sorted, SORT_STRING);

    return array_map('strval', $sorted);
}

$lists = [];
foreach (LISTS as $language => [$name, $sha256]) {
    $lists[$language] = wordsOf(fetchedList($name, $sha256));
    printf("%s: %d words\n", $language, count($lists[$language]));
}

$target = __DIR__ . '/stopwords.php';
file_put_contents($target, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($lists, true) . ";\n");
printf("%s: %d bytes\n", $target, filesize($target));
