<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

require __DIR__ . '/render.php';

const SHARD_PATTERN = '/^1-\d{5}-of-\d{5}\.gz$/';
const TOKEN = '/^(\p{L}+)(?:_(NOUN|VERB|ADJ|ADV|PRON|DET|ADP|NUM|CONJ|PRT|X))?$/u';
const KEPT_YEARS = '/\t(?:19[5-9]\d|20[01]\d),(\d+),/';
const CAPITALISED = '/^\p{Lu}/u';
const MIN_LETTERS = 3;
const SIZE_BUDGET = 6_291_456;
const FORM_COUNT = 290_000;

ini_set('memory_limit', '-1');

/** Counts per casefolded form over the kept years, summed over its spellings and its part-of-speech tags. */
final class FormCounts
{
    /** @var array<string, int> */
    public array $total = [];
    /** @var array<string, int> */
    public array $capitalised = [];
    /** @var array<string, int> */
    public array $tagged = [];
    /** @var array<string, int> */
    public array $noun = [];

    public function addShard(string $path): void
    {
        $shard = gzopen($path, 'rb') ?: throw new RuntimeException('Cannot open ' . $path);
        while (($line = gzgets($shard)) !== false) {
            $this->addLine($line);
        }
        gzclose($shard);
    }

    private function addLine(string $line): void
    {
        $tab = strpos($line, "\t");
        if ($tab === false || preg_match(TOKEN, substr($line, 0, $tab), $token) !== 1) {
            return;
        }
        $count = keptCount($line);
        $form = casefolded($token[1]);
        if ($count === 0 || mb_strlen($form) < MIN_LETTERS) {
            return;
        }
        if (!isset($token[2])) {
            $this->addSpelling($form, $token[1], $count);

            return;
        }
        $this->addTag($form, $token[2], $count);
    }

    private function addSpelling(string $form, string $spelling, int $count): void
    {
        $this->total[$form] = ($this->total[$form] ?? 0) + $count;
        if (preg_match(CAPITALISED, $spelling) === 1) {
            $this->capitalised[$form] = ($this->capitalised[$form] ?? 0) + $count;
        }
    }

    private function addTag(string $form, string $tag, int $count): void
    {
        $this->tagged[$form] = ($this->tagged[$form] ?? 0) + $count;
        if ($tag === 'NOUN') {
            $this->noun[$form] = ($this->noun[$form] ?? 0) + $count;
        }
    }

    public function isNoun(string $form): bool
    {
        $tagged = $this->tagged[$form] ?? 0;
        if ($tagged === 0) {
            return 2 * ($this->capitalised[$form] ?? 0) >= $this->total[$form];
        }

        return 2 * ($this->noun[$form] ?? 0) >= $tagged;
    }

    public function isUntagged(string $form): bool
    {
        return ($this->tagged[$form] ?? 0) === 0;
    }

    /** @return list<string> forms by count, descending; a tie goes to the form that sorts first */
    public function ranked(): array
    {
        $forms = array_map(strval(...), array_keys($this->total));
        $counts = array_values($this->total);
        array_multisort($counts, SORT_DESC, SORT_NUMERIC, $forms, SORT_ASC, SORT_STRING);

        return $forms;
    }
}

function keptCount(string $line): int
{
    preg_match_all(KEPT_YEARS, $line, $matches);

    return (int) array_sum($matches[1]);
}

function casefolded(string $token): string
{
    $folded = mb_convert_case($token, MB_CASE_FOLD, 'UTF-8');
    $composed = Normalizer::normalize($folded, Normalizer::FORM_KC);

    return is_string($composed) ? $composed : $folded;
}

/** floor(log2(maxCount / count)), in integers */
function frequencyClass(int $count, int $maxCount): int
{
    $class = 0;
    while ($count << ($class + 1) <= $maxCount) {
        ++$class;
    }

    return $class;
}

/** One kept form: its section and its frequency class. */
final readonly class RankedForm
{
    public function __construct(public string $form, public bool $isNoun, public int $class)
    {
    }
}

/** @return list<RankedForm> the FORM_COUNT most frequent forms */
function keptForms(FormCounts $counts): array
{
    $ranked = $counts->ranked();
    $maxCount = $counts->total[$ranked[0]];

    return array_map(
        static fn (string $form): RankedForm => new RankedForm(
            $form,
            $counts->isNoun($form),
            frequencyClass($counts->total[$form], $maxCount),
        ),
        array_slice($ranked, 0, FORM_COUNT),
    );
}

/** @param list<RankedForm> $forms */
function writeLexicon(array $forms): int
{
    $nouns = [];
    $others = [];
    foreach ($forms as $form) {
        if ($form->isNoun) {
            $nouns[$form->form] = $form->class;
        } else {
            $others[$form->form] = $form->class;
        }
    }

    return writeLexiconFiles(__DIR__, $nouns, $others);
}

$shardDirectory = $argv[1] ?? throw new RuntimeException('Usage: generate.php <shard directory>');
$shards = array_values(array_filter(scandir($shardDirectory) ?: [], static fn (string $name): bool => preg_match(SHARD_PATTERN, $name) === 1));
if ($shards === []) {
    throw new RuntimeException('No shards in ' . $shardDirectory);
}

$counts = new FormCounts();
foreach ($shards as $shard) {
    $started = hrtime(true);
    $counts->addShard($shardDirectory . '/' . $shard);
    fprintf(STDERR, "%s: %d forms so far, %.0f s, %.0f MB\n", $shard, count($counts->total), (hrtime(true) - $started) / 1e9, memory_get_peak_usage() / 1048576);
}

$kept = keptForms($counts);
$size = writeLexicon($kept);
if ($size > SIZE_BUDGET) {
    throw new RuntimeException('The lexicon exceeds the size budget.');
}

$nouns = count(array_filter($kept, static fn (RankedForm $form): bool => $form->isNoun));
$untagged = count(array_filter($kept, static fn (RankedForm $form): bool => $counts->isUntagged($form->form)));
printf("N %d\nsize %d bytes\nnouns %d\nothers %d\nuntagged (capitalisation rule) %d\nshards %d\n", count($kept), $size, $nouns, count($kept) - $nouns, $untagged, count($shards));
