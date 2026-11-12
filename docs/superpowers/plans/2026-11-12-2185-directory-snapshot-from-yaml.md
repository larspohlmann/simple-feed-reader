# Feed directory snapshot reads the InfoBubble Sources YAML — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `backend/resources/feed-directory/snapshot.php` builds the directory from InfoBubble's `Sources/all.sources.by.hostnames.rss.yaml` instead of the stale `docs/data/catalog.js`, and the committed data is regenerated from it.

**Architecture:** The script keeps its output exactly — `countries/<ISO>.json.gz`, `index.json`, `ABOUT.txt`, `LICENSE.txt`, `SOURCE` — so neither the API nor the SPA changes. Only its input side changes: it finds the YAML's latest commit, downloads the YAML at that commit, parses it with `symfony/yaml` (already a backend dependency), and maps each `Country → host → {rank, languages, avg_article_length, summary, feeds}` entry onto the existing `CountryFile` writer. A `feeds` key `Podcasts: <Category>` is a podcast group of `<Category>`; any other key is a channel group.

**Tech Stack:** PHP 8.5 CLI, `symfony/yaml` 8.1 via `backend/vendor/autoload.php`, ICU `Locale`.

**Spec:** GitHub issue #2185 (`gh issue view 2185`): Lars's decision of 2026-11-12 reverses the #2176 choice of `catalog.js`. The directory's design is `docs/superpowers/specs/2026-11-11-2176-feed-directory-design.md` (§ Data).

## Global Constraints

- Keep the output format (`countries/<ISO>.json.gz` + `index.json`) unchanged so the frontend needs no change; regenerate the files; `SOURCE` records the YAML URL + commit; ABOUT/LICENSE still ODbL.
- Verify counts against the old snapshot and spot-check a few hosts (e.g. spiegel.de, london.gov.uk, theguardian.com).
- Data stays in files: no DB import, no refresh process; the script stays a manual run.
- Commit format `type(#2185): …`; no Claude attribution.
- Comments: default to none (CLAUDE.md).

## Facts established while planning (2026-11-12)

- YAML at commit `41a0a1aa10dd3520fce4fc75f19141bfb0c2a251` (2026-11-11T23:01:54Z): 20 MB, 232 countries, 50,642 hosts. Every host has `rank`, `summary`, `feeds`; `rank` is `null` for 25,902, `avg_article_length` is missing for 3,491, `languages` is missing or null for 81, `summary` null for 1.
- Feed group keys: the 9 categories (`World / International`, `Politics & Society`, `Other`, `Local News`, `Lifestyle & Culture`, `Business & Economy`, `Sports`, `Opinion`, `Science & Technology`) and the same 9 prefixed `Podcasts: `.
- The current snapshot (catalog.js `fcdc3896…`, 2026-10-15): 231 countries, 42,020 domains. A host with no languages is stored with `[]`; a null rank or length is stored as `null`.
- `symfony/yaml` parses the file in a few seconds with a 2 GB memory limit.
- The country files keep upstream order: hosts in YAML order within a country, groups in YAML order within a host, URLs in YAML order.

---

### Task 1: The snapshot script reads the YAML

**Files:**
- Modify: `backend/resources/feed-directory/snapshot.php`
- Modify: `docs/superpowers/specs/2026-11-11-2176-feed-directory-design.md` (§ Source and licence, the `SOURCE` bullet, § Snapshot script)

**Interfaces:**
- Consumes: the existing `CountryFile` class, `isoCodes()`, `regionCodesByName()`, `REGION_OVERRIDES`, `writeCountryFiles()`, `writeIndex()`, `download()`, `upstreamUrl()` in the same file — unchanged.
- Produces: the same files as today.

- [ ] **Step 1: Replace the catalog input with the YAML**

At the top, after `declare(strict_types=1);`:

```php
require __DIR__ . '/../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

const UPSTREAM = 'https://raw.githubusercontent.com/infobubble/infobubble-support/';
const SOURCES = 'Sources/all.sources.by.hostnames.rss.yaml';
const COMMITS = 'https://api.github.com/repos/infobubble/infobubble-support/commits?path=' . SOURCES . '&per_page=1';
const PODCASTS = 'Podcasts: ';
const REGION_OVERRIDES = ['St. Helena' => 'SH'];

ini_set('memory_limit', '2G');
```

Delete `unwrap()`, `groupByCountry()`, `catalogLanguageSites()`, `buildCountryIndex()` and the `CATALOG` constant. Rename `latestCatalogCommit()` to `latestSourcesCommit()` (same body). Add:

```php
/** @return array{string, int, list<string>} category, podcast flag, feed URLs */
function feedGroup(string $name, array $urls): array
{
    return str_starts_with($name, PODCASTS)
        ? [substr($name, strlen(PODCASTS)), 1, array_values($urls)]
        : [$name, 0, array_values($urls)];
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
                array_map(feedGroup(...), array_keys($site['feeds'] ?? []), array_values($site['feeds'] ?? [])),
                $site['summary'] ?? null,
            );
        }
        if ($file->siteCount() > 0) {
            $files[$country] = $file;
        }
    }

    return $files;
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
```

`(string) $host` matters: a YAML key that looks numeric would arrive as an int. `writeSource()` writes `'url: ' . upstreamUrl($commit['sha'], SOURCES)`; its ABOUT/LICENSE downloads stay. `writeCountryFiles()` keys files by country name now, so it must write `$file->code` instead of the array key:

```php
foreach ($files as $file) {
    file_put_contents(sprintf('%s/countries/%s.json.gz', $target, $file->code), gzencode($file->toJson(), 9));
}
```

The run block becomes:

```php
$target = __DIR__;
$commit = latestSourcesCommit();
$sources = Yaml::parse(download(upstreamUrl($commit['sha'], SOURCES)));
$files = countryFiles($sources);

writeCountryFiles($files, $target);
writeIndex(countryIndex($files), languageSites($files), $target);
writeSource($target, $commit);

printf("%d countries, %d domains\n", count($files), array_sum(array_map(static fn (CountryFile $file): int => $file->siteCount(), $files)));
```

`isoCodes()` throws on an unmapped name; if the 232nd country is new and unmapped, add it to `REGION_OVERRIDES` with its ISO 3166-1 alpha-2 code (check with `php -r 'echo Locale::getDisplayRegion("und_XX", "en");'`) and note it in the report.

- [ ] **Step 2: Lint the script**

Run from `backend/`: `php -l resources/feed-directory/snapshot.php` and `vendor/bin/phpcs --standard=PSR12 resources/feed-directory/snapshot.php`.
Expected: no syntax errors. Fix PSR-12 findings the change introduced (the file is outside the `composer cs` scope; long lines that were already there may stay).

- [ ] **Step 3: Update the design spec**

In `docs/superpowers/specs/2026-11-11-2176-feed-directory-design.md`:
- § Source and licence: replace the first sentence's `docs/data/catalog.js` description with: the snapshot reads `Sources/all.sources.by.hostnames.rss.yaml` from `infobubble/infobubble-support` (`<Country>: <host>: {rank, languages, avg_article_length, summary, feeds: {<Category> | 'Podcasts: <Category>': [urls]}}`), the source the InfoBubble page's `catalog.js` is built from, which upstream updates more often (#2185 reversed the original choice of `catalog.js`). Keep the licence/attribution sentences.
- Replace the "Upstream shape" block and the counts paragraph after it with the YAML shape above and the counts from "Facts established while planning".
- The `SOURCE` bullet: "the upstream URL and commit SHA of the YAML, its commit date, and the fetch date."
- § Snapshot script: "it downloads the YAML, `ABOUT.txt` and `LICENSE.txt`, parses the YAML with `symfony/yaml` …".

- [ ] **Step 4: Commit**

```bash
git add backend/resources/feed-directory/snapshot.php docs/superpowers/specs/2026-11-11-2176-feed-directory-design.md
git commit -m "feat(#2185): the feed directory snapshot reads InfoBubble's Sources YAML"
```

---

### Task 2: Regenerate the data and verify it

**Files:**
- Regenerate: `backend/resources/feed-directory/countries/*.json.gz`, `index.json`, `SOURCE`, `ABOUT.txt`, `LICENSE.txt`

- [ ] **Step 1: Keep the old snapshot for comparison**

```bash
OLD=<scratch dir>/old-snapshot; mkdir -p "$OLD" && cp -R backend/resources/feed-directory/{countries,index.json,SOURCE} "$OLD"/
```

- [ ] **Step 2: Run the script**

From the repo root: `php backend/resources/feed-directory/snapshot.php`
Expected: `232 countries, 50642 domains` (or the counts of a newer upstream commit — then record them). `SOURCE` names `Sources/all.sources.by.hostnames.rss.yaml` at the commit SHA.

- [ ] **Step 3: Verify counts and shape**

Write a throwaway PHP script in the scratch dir (not in the repo) that prints, old vs new: country count, total sites, sites per country for DE/US/GB, per-language totals for de/en, the set of country codes added or removed, and asserts every new country file decodes to `{country, categories, languages, domains}` with each domain a 6-tuple `[string, int|null, int|null, int[], [int, 0|1, string[]][], string|null]` and every category/language index in range. Expected: shape assertions pass; counts grow (newer upstream), no country disappears unless upstream dropped it — explain any that does.

- [ ] **Step 4: Spot-check hosts against the YAML**

For `spiegel.de` (DE), `london.gov.uk` (GB) and `theguardian.com` (GB): print the YAML entry and the generated domain tuple (categories and languages resolved through the file's arrays) and confirm rank, length, languages, summary, and every group's category, podcast flag and URL list match exactly. Also diff each against the old snapshot's tuple and summarise what upstream changed.

- [ ] **Step 5: Run the directory's tests and the smoke**

From `backend/`: `php bin/phpunit tests/Controller/Api/FeedDirectoryControllerTest.php tests/Service/FeedDirectory` (they read the committed files).
From `frontend/`: `npx playwright test e2e/feed-directory.spec.ts` (stubs its own files; proves nothing broke in the wiring).
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add backend/resources/feed-directory
git commit -m "chore(#2185): regenerate the feed directory from the Sources YAML at 41a0a1a"
```

(Use the real short SHA from `SOURCE`.)

- [ ] **Step 7: Live check (controller)**

On `http://localhost:4200/settings/feed-directory` the summary shows the new site count for the scope, and spiegel.de / tagesschau.de rows render with their categories and feed counts.

---

### Task 3: Countries are keyed by their ISO code, not a deprecated alias (#2190, folded in by Lars)

`regionCodesByName()` walks AA–ZZ, and ICU names a deprecated alias exactly like its ISO code, so the later alias wins: UK/GB, FX/FR, SU/RU, YU/RS, ZR/CD, TP/TL, DY/BJ, HV/BF. ICU's own `metadata` bundle lists every deprecated territory code (`alias/territory`, e.g. `UK → GB`).

Ruling (Lars, 2026-11-12): nothing here is released, so no backup-restore mapping and no migration of saved scopes.

- [ ] Skip every code ICU lists under `alias/territory` in `regionCodesByName()`:

```php
function deprecatedRegionCodes(): array
{
    $aliases = ResourceBundle::create('metadata', 'ICUDATA', false)['alias']['territory'];
    $codes = [];
    foreach ($aliases as $code => $alias) {
        $codes[$code] = true;
    }

    return $codes;
}
```

and `if ($name !== '' && $name !== $code && !isset($deprecated[$code]))`.
- [ ] Regenerate the snapshot (same upstream commit); confirm the eight countries now carry GB, FR, RU, RS, CD, TL, BJ, BF, that the other 224 keys are unchanged, and that every file's content is otherwise identical.
- [ ] Spec: the country-key paragraph says the script drops ICU's deprecated aliases.
- [ ] Commit `fix(#2190): the feed directory keys every country by its ISO code`.
