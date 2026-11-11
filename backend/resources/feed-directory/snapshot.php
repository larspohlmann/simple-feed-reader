<?php

declare(strict_types=1);

const UPSTREAM = 'https://raw.githubusercontent.com/infobubble/infobubble-support/';
const CATALOG = 'docs/data/catalog.js';
const COMMITS = 'https://api.github.com/repos/infobubble/infobubble-support/commits?path=' . CATALOG . '&per_page=1';
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

final class CountryFile
{
    /** @var list<string> */
    private array $categories = [];
    /** @var list<string> */
    private array $languages = [];
    /** @var list<array<int, mixed>> */
    private array $domains = [];

    public function __construct(public readonly string $code)
    {
    }

    /** @param list<string> $languages @param list<array{string, int, list<string>}> $groups */
    public function addDomain(
        string $host,
        ?int $rank,
        ?int $averageLength,
        array $languages,
        array $groups,
        ?string $summary,
    ): void {
        $this->domains[] = [
            $host,
            $rank,
            $averageLength,
            array_map(fn (string $language): int => $this->languageIndex($language), $languages),
            array_map(fn (array $group): array => [$this->categoryIndex($group[0]), $group[1], $group[2]], $groups),
            $summary,
        ];
    }

    public function siteCount(): int
    {
        return count($this->domains);
    }

    /** @return array<string, int> domains carrying each language, most common first */
    public function languageSites(): array
    {
        $counts = [];
        foreach ($this->domains as $domain) {
            foreach ($domain[3] as $languageIndex) {
                $language = $this->languages[$languageIndex];
                $counts[$language] = ($counts[$language] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    public function toJson(): string
    {
        return json_encode(
            ['country' => $this->code, 'categories' => $this->categories, 'languages' => $this->languages, 'domains' => $this->domains],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    private function languageIndex(string $language): int
    {
        $position = array_search($language, $this->languages, true);
        if ($position === false) {
            $this->languages[] = $language;

            return count($this->languages) - 1;
        }

        return $position;
    }

    private function categoryIndex(string $category): int
    {
        $position = array_search($category, $this->categories, true);
        if ($position === false) {
            $this->categories[] = $category;

            return count($this->categories) - 1;
        }

        return $position;
    }
}

/** @param list<string> $codes @return array<string, CountryFile> */
function groupByCountry(array $catalog, array $codes): array
{
    $files = [];
    foreach (
        $catalog['domains'] as [$host, $countryIndex, $rank, $averageLength, $languageIndexes, $groups, $summary]
    ) {
        $code = $codes[$countryIndex];
        $files[$code] ??= new CountryFile($code);
        $languages = array_map(static fn (int $languageIndex): string => $catalog['languages'][$languageIndex], $languageIndexes);
        $categorised = array_map(
            static fn (array $group): array => [$catalog['categories'][$group[0]], $group[1], $group[2]],
            $groups,
        );
        $files[$code]->addDomain($host, $rank, $averageLength, $languages, $categorised, $summary);
    }

    return $files;
}

/** @param array<string, CountryFile> $files */
function writeCountryFiles(array $files, string $target): void
{
    if (!is_dir($target . '/countries')) {
        mkdir($target . '/countries');
    }
    array_map('unlink', glob($target . '/countries/*.json.gz') ?: []);
    foreach ($files as $code => $file) {
        file_put_contents(sprintf('%s/countries/%s.json.gz', $target, $code), gzencode($file->toJson(), 9));
    }
}

/** @return array<string, int> domains per language across the whole catalog, most common first */
function catalogLanguageSites(array $catalog): array
{
    $counts = [];
    foreach ($catalog['domains'] as $domain) {
        foreach ($domain[4] as $languageIndex) {
            $language = $catalog['languages'][$languageIndex];
            $counts[$language] = ($counts[$language] ?? 0) + 1;
        }
    }
    arsort($counts);

    return $counts;
}

/** @param array<string, CountryFile> $files @param list<string> $codes @return list<array<string, mixed>> */
function buildCountryIndex(array $catalog, array $files, array $codes): array
{
    $countries = [];
    foreach ($catalog['countries'] as $countryIndex => $name) {
        $file = $files[$codes[$countryIndex]] ?? null;
        if ($file === null) {
            continue;
        }
        $countries[] = ['code' => $file->code, 'name' => $name, 'sites' => $file->siteCount(), 'languages' => $file->languageSites()];
    }
    usort($countries, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

    return $countries;
}

function writeIndex(array $countries, array $languageSites, string $target): void
{
    file_put_contents(
        $target . '/index.json',
        json_encode(['countries' => $countries, 'languages' => $languageSites], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n",
    );
}

function latestCatalogCommit(): array
{
    return json_decode(download(COMMITS), true, flags: JSON_THROW_ON_ERROR)[0];
}

function upstreamUrl(string $sha, string $path): string
{
    return UPSTREAM . $sha . '/' . $path;
}

function writeSource(string $target, array $commit): void
{
    file_put_contents($target . '/ABOUT.txt', download(upstreamUrl($commit['sha'], 'Sources/ABOUT.txt')));
    file_put_contents($target . '/LICENSE.txt', download(upstreamUrl($commit['sha'], 'Sources/LICENSE.txt')));
    file_put_contents($target . '/SOURCE', implode("\n", [
        'url: ' . upstreamUrl($commit['sha'], CATALOG),
        'commit: ' . $commit['sha'],
        'committed: ' . $commit['commit']['committer']['date'],
        'fetched: ' . gmdate('Y-m-d'),
    ]) . "\n");
}

$target = __DIR__;
$commit = latestCatalogCommit();
$catalog = unwrap(download(upstreamUrl($commit['sha'], CATALOG)));
$codes = isoCodes($catalog['countries']);
$files = groupByCountry($catalog, $codes);

writeCountryFiles($files, $target);
writeIndex(buildCountryIndex($catalog, $files, $codes), catalogLanguageSites($catalog), $target);
writeSource($target, $commit);

printf("%d countries, %d domains\n", count($files), count($catalog['domains']));
