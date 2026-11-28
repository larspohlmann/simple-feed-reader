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

/** Feed footers ("Der Beitrag … erschien zuerst auf …") and read-more links, seen in Your stream (#2601). */
const BOILERPLATE = [
    'de' => ['erschien', 'weiterlesen'],
];

/** Google Books Ngram v3 1-gram shards, by language: the closed-class supplement (#2231). */
const SHARDS = [
    'fr' => [
        'fre/1-00000-of-00006.gz' => 'c09240100c1d51c479faaf7ecd87163d2460f7f6d8c0244112b2727d96cae8e1',
        'fre/1-00001-of-00006.gz' => '9dcda2040ee9e63333fc515db51dad958725afb9a71b70a707105564ed64eb3b',
        'fre/1-00002-of-00006.gz' => '389f4b15e40a73e1ad57ae807eaec7f0a56725e8897dde533ed77f1dc30140ea',
        'fre/1-00003-of-00006.gz' => 'c6a38bbcfb00bdc4ac8cc8e22936d0835bfbb07f798e36dcf21a024dbddf3acd',
        'fre/1-00004-of-00006.gz' => '978359563dceae0dbbe06457c07079eb0209471bef50f64fde2aa3b95534124c',
        'fre/1-00005-of-00006.gz' => '2ccc18b4e6f0bebe62fa2d251265b663e09990eef3827620f445d32cceded64e',
    ],
    'es' => [
        'spa/1-00000-of-00003.gz' => 'c28dfa7f433317baf75429a57b1dab1d6ec016ad325feef0f30ab4f79d63f386',
        'spa/1-00001-of-00003.gz' => 'b6396078773aa299ea010d6cfdef437a97055532df7d65c0a90914141e7ef0f6',
        'spa/1-00002-of-00003.gz' => '720b00fde16df0854dd8ccf1a37dcc021d37a568a9c71f063cd995124d001e3e',
    ],
];
const TAGGED = '/^(\p{L}+)_(NOUN|VERB|ADJ|ADV|PRON|DET|ADP|NUM|CONJ|PRT|X)$/u';
const CLOSED_CLASS_TAGS = ['ADP' => true, 'ADV' => true, 'CONJ' => true, 'DET' => true, 'PRON' => true, 'PRT' => true];
const KEPT_YEARS = '/\t(?:19[5-9]\d|20[01]\d),(\d+),/';
const RANKED_FORMS = 1_000;

ini_set('memory_limit', '-1');

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
function snowballWords(string $body): array
{
    $words = [];
    foreach (explode("\n", $body) as $line) {
        $tokens = preg_split('/\s+/u', trim(explode('|', $line, 2)[0]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($tokens !== [] && preg_match(WORD, casefolded($tokens[0])) === 1) {
            $words[] = casefolded($tokens[0]);
        }
    }

    return $words;
}

/**
 * Adds one shard's tagged counts over 1950–2019 per casefolded form: all tags, and the closed-class tags alone.
 *
 * @param array<string, int> $tagged
 * @param array<string, int> $closedClass
 */
function addShard(string $path, array &$tagged, array &$closedClass): void
{
    $shard = gzopen($path, 'rb') ?: throw new RuntimeException('Cannot open ' . $path);
    while (($line = gzgets($shard)) !== false) {
        $token = substr($line, 0, (int) strpos($line, "\t"));
        if (!str_contains($token, '_') || preg_match(TAGGED, $token, $match) !== 1) {
            continue;
        }
        preg_match_all(KEPT_YEARS, $line, $years);
        $count = (int) array_sum($years[1]);
        if ($count === 0) {
            continue;
        }
        $form = casefolded($match[1]);
        $tagged[$form] = ($tagged[$form] ?? 0) + $count;
        if (isset(CLOSED_CLASS_TAGS[$match[2]])) {
            $closedClass[$form] = ($closedClass[$form] ?? 0) + $count;
        }
    }
    gzclose($shard);
}

/**
 * @param array<string, int> $tagged
 * @param array<string, int> $closedClass
 * @return list<string> the RANKED_FORMS most frequent forms whose tags are at least half closed-class
 */
function closedClassForms(array $tagged, array $closedClass): array
{
    $forms = array_map(strval(...), array_keys($tagged));
    $counts = array_values($tagged);
    array_multisort($counts, SORT_DESC, SORT_NUMERIC, $forms, SORT_ASC, SORT_STRING);

    return array_values(array_filter(
        array_slice($forms, 0, RANKED_FORMS),
        static fn (string $form): bool => 2 * ($closedClass[$form] ?? 0) >= $tagged[$form],
    ));
}

/** @return list<string> */
function closedClassWords(string $shardDirectory, string $language): array
{
    $tagged = [];
    $closedClass = [];
    foreach (SHARDS[$language] ?? [] as $shard => $sha256) {
        $path = $shardDirectory . '/' . $shard;
        if (!is_file($path) || hash_file('sha256', $path) !== $sha256) {
            throw new RuntimeException('Missing shard or SHA-256 mismatch: ' . $path);
        }
        addShard($path, $tagged, $closedClass);
    }

    return array_values(array_filter(closedClassForms($tagged, $closedClass), static fn (string $form): bool => preg_match(WORD, $form) === 1));
}

/**
 * @param list<string> $words
 * @return list<string>
 */
function sortedDistinct(array $words): array
{
    $distinct = array_map(strval(...), array_keys(array_fill_keys($words, true)));
    sort($distinct, SORT_STRING);

    return $distinct;
}

$shardDirectory = $argv[1] ?? throw new RuntimeException('Usage: generate.php <directory holding fre/ and spa/ Ngram shards>');
$lists = [];
foreach (LISTS as $language => [$name, $sha256]) {
    $snowball = sortedDistinct(snowballWords(fetchedList($name, $sha256)));
    $closedClass = closedClassWords($shardDirectory, $language);
    $lists[$language] = sortedDistinct([...$snowball, ...$closedClass, ...BOILERPLATE[$language] ?? []]);
    printf(
        "%s: %d words (Snowball %d, closed-class %d, of them new %d)\n",
        $language,
        count($lists[$language]),
        count($snowball),
        count($closedClass),
        count($lists[$language]) - count($snowball),
    );
}

$target = __DIR__ . '/stopwords.php';
file_put_contents($target, "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($lists, true) . ";\n");
printf("%s: %d bytes\n", $target, filesize($target));
