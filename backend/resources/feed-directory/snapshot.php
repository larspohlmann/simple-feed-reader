<?php

declare(strict_types=1);

const UPSTREAM = 'https://raw.githubusercontent.com/infobubble/infobubble-support/main/';
const COMMITS = 'https://api.github.com/repos/infobubble/infobubble-support/commits?path=docs/data/catalog.js&per_page=1';
const REGION_OVERRIDES = ['St. Helena' => 'SH'];

function download(string $url): string
{
    $context = stream_context_create(['http' => ['header' => "User-Agent: feed-directory-snapshot\r\n"]]);
    $body = file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Download failed: ' . $url);
    }

    return $body;
}

/** @return array<string, string> English CLDR region name => ISO code */
function regionCodesByName(): array
{
    $codes = [];
    foreach (range('A', 'Z') as $first) {
        foreach (range('A', 'Z') as $second) {
            $code = $first . $second;
            $name = Locale::getDisplayRegion('und_' . $code, 'en');
            if ($name !== '' && $name !== $code) {
                $codes[$name] = $code;
            }
        }
    }

    return [...$codes, ...REGION_OVERRIDES];
}

/** @param list<string> $names @return list<string> */
function isoCodes(array $names): array
{
    $byName = regionCodesByName();
    $codes = [];
    foreach ($names as $name) {
        $codes[] = $byName[$name] ?? throw new RuntimeException('No ISO code for ' . $name);
    }
    if (count(array_unique($codes)) !== count($codes)) {
        throw new RuntimeException('Two countries share an ISO code.');
    }

    return $codes;
}

function unwrap(string $script): array
{
    $start = strpos($script, '{');
    $end = strrpos($script, '}');
    if ($start === false || $end === false) {
        throw new RuntimeException('catalog.js holds no JSON object.');
    }

    return json_decode(substr($script, $start, $end - $start + 1), true, flags: JSON_THROW_ON_ERROR);
}

function localIndex(array &$list, string $value): int
{
    $position = array_search($value, $list, true);
    if ($position === false) {
        $list[] = $value;
        return count($list) - 1;
    }

    return $position;
}

$target = __DIR__;
$catalog = unwrap(download(UPSTREAM . 'docs/data/catalog.js'));
$codes = isoCodes($catalog['countries']);

$files = [];
$index = [];
$languageSites = [];
foreach ($catalog['domains'] as [$host, $countryIndex, $rank, $avgLength, $languageIndexes, $groups, $summary]) {
    $code = $codes[$countryIndex];
    $files[$code] ??= ['country' => $code, 'categories' => [], 'languages' => [], 'domains' => []];
    $file = &$files[$code];
    $languages = [];
    foreach ($languageIndexes as $languageIndex) {
        $language = $catalog['languages'][$languageIndex];
        $languages[] = localIndex($file['languages'], $language);
        $languageSites[$language] = ($languageSites[$language] ?? 0) + 1;
        $index[$code]['languages'][$language] = ($index[$code]['languages'][$language] ?? 0) + 1;
    }
    $localGroups = [];
    foreach ($groups as [$categoryIndex, $isPodcast, $urls]) {
        $localGroups[] = [localIndex($file['categories'], $catalog['categories'][$categoryIndex]), $isPodcast, $urls];
    }
    $file['domains'][] = [$host, $rank, $avgLength, $languages, $localGroups, $summary];
    $index[$code]['sites'] = ($index[$code]['sites'] ?? 0) + 1;
    unset($file);
}

if (!is_dir($target . '/countries')) {
    mkdir($target . '/countries');
}
array_map('unlink', glob($target . '/countries/*.json.gz') ?: []);
foreach ($files as $code => $file) {
    file_put_contents(
        sprintf('%s/countries/%s.json.gz', $target, $code),
        gzencode(json_encode($file, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 9),
    );
}

$countries = [];
foreach ($catalog['countries'] as $countryIndex => $name) {
    $code = $codes[$countryIndex];
    if (!isset($index[$code])) {
        continue;
    }
    arsort($index[$code]['languages']);
    $countries[] = ['code' => $code, 'name' => $name, 'sites' => $index[$code]['sites'], 'languages' => $index[$code]['languages']];
}
usort($countries, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));
arsort($languageSites);
file_put_contents(
    $target . '/index.json',
    json_encode(['countries' => $countries, 'languages' => $languageSites], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n",
);

file_put_contents($target . '/ABOUT.txt', download(UPSTREAM . 'Sources/ABOUT.txt'));
file_put_contents($target . '/LICENSE.txt', download(UPSTREAM . 'Sources/LICENSE.txt'));
$commit = json_decode(download(COMMITS), true, flags: JSON_THROW_ON_ERROR)[0];
file_put_contents($target . '/SOURCE', implode("\n", [
    'url: ' . UPSTREAM . 'docs/data/catalog.js',
    'commit: ' . $commit['sha'],
    'committed: ' . $commit['commit']['committer']['date'],
    'fetched: ' . gmdate('Y-m-d'),
]) . "\n");

printf("%d countries, %d domains\n", count($files), count($catalog['domains']));
