<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

const UPSTREAM = 'https://raw.githubusercontent.com/infobubble/infobubble-support/';
const SOURCES = 'Sources/all.sources.by.hostnames.rss.yaml';
const COMMITS = 'https://api.github.com/repos/infobubble/infobubble-support/commits?path=' . SOURCES . '&per_page=1';
const PODCASTS = 'Podcasts: ';
const REGION_OVERRIDES = ['St. Helena' => 'SH'];

ini_set('memory_limit', '2G');

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

/** @param array<string, list<string>> $feeds @return list<array{string, int, list<string>}> category, podcast flag, feed URLs */
function feedGroups(array $feeds): array
{
    $groups = [];
    foreach ($feeds as $name => $urls) {
        $podcast = str_starts_with($name, PODCASTS);
        $groups[] = [$podcast ? substr($name, strlen(PODCASTS)) : $name, (int) $podcast, array_values($urls)];
    }

    return $groups;
}

/** @param array<string, array<string, array<string, mixed>>> $sources @return array<string, CountryFile> */
function countryFiles(array $sources): array
{
    $codes = array_combine(array_keys($sources), isoCodes(array_keys($sources)));
    $files = [];
    foreach ($sources as $country => $hosts) {
        $file = new CountryFile($codes[$country]);
        foreach ($hosts ?? [] as $host => $site) {
            $file->addDomain(
                (string) $host,
                $site['rank'] ?? null,
                $site['avg_article_length'] ?? null,
                $site['languages'] ?? [],
                feedGroups($site['feeds'] ?? []),
                $site['summary'] ?? null,
            );
        }
        if ($file->siteCount() > 0) {
            $files[$country] = $file;
        }
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
    foreach ($files as $file) {
        file_put_contents(sprintf('%s/countries/%s.json.gz', $target, $file->code), gzencode($file->toJson(), 9));
    }
}

/** @param array<string, CountryFile> $files @return list<array<string, mixed>> */
function countryIndex(array $files): array
{
    $countries = [];
    foreach ($files as $name => $file) {
        $countries[] = ['code' => $file->code, 'name' => $name, 'sites' => $file->siteCount(), 'languages' => $file->languageSites()];
    }
    usort($countries, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

    return $countries;
}

/** @param array<string, CountryFile> $files @return array<string, int> sites per language across every country, most common first */
function languageSites(array $files): array
{
    $counts = [];
    foreach ($files as $file) {
        foreach ($file->languageSites() as $language => $sites) {
            $counts[$language] = ($counts[$language] ?? 0) + $sites;
        }
    }
    arsort($counts);

    return $counts;
}

function writeIndex(array $countries, array $languageSites, string $target): void
{
    file_put_contents(
        $target . '/index.json',
        json_encode(['countries' => $countries, 'languages' => $languageSites], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n",
    );
}

function latestSourcesCommit(): array
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
        'url: ' . upstreamUrl($commit['sha'], SOURCES),
        'commit: ' . $commit['sha'],
        'committed: ' . $commit['commit']['committer']['date'],
        'fetched: ' . gmdate('Y-m-d'),
    ]) . "\n");
}

$target = __DIR__;
$commit = latestSourcesCommit();
$sources = Yaml::parse(download(upstreamUrl($commit['sha'], SOURCES)));
$files = countryFiles($sources);

writeCountryFiles($files, $target);
writeIndex(countryIndex($files), languageSites($files), $target);
writeSource($target, $commit);

printf("%d countries, %d domains\n", count($files), array_sum(array_map(static fn (CountryFile $file): int => $file->siteCount(), $files)));
