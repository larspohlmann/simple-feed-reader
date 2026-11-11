# Feed directory Implementation Plan (#2176)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Settings section that lists the InfoBubble sources directory for the user's chosen countries and languages, filtered in the browser, where any feed is one tap from the existing add-feed dialog.

**Architecture:** A one-off PHP script splits InfoBubble's `docs/data/catalog.js` into one gzipped JSON file per ISO country plus an `index.json`, committed under `backend/resources/feed-directory/`. The API streams those files unchanged (no decoding, no DB import) and stores the user's country/language scope on `Preferences`. The Angular section loads the scoped country files, filters and sorts them with signals, and opens `AddFeedDialogComponent` for a chosen feed.

**Tech Stack:** Symfony 8.1 / PHP 8.5 (`BinaryFileResponse`, Doctrine migration, PHPUnit), Angular 20 standalone + signals, CDK virtual scroll and overlay, transloco, Jest, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-11-2176-feed-directory-design.md`. Visual reference: variant C of `docs/superpowers/specs/2026-11-11-2176-feed-directory-mockup.html` — match it.

## Global Constraints

- Name: everything is **FeedDirectory**; never `Catalog` (that is the onboarding catalog) and never `…Source`.
- Country keys are upper-case ISO 3166-1 alpha-2 codes (`DE`); language codes are upstream's ISO 639-1 codes (`de`).
- No DB import of directory data; no refresh process wired anywhere. `snapshot.php` is run by hand.
- PHP never decodes a country file to serve it. Only `index.json` (small) is decoded.
- Scope: both lists non-empty, or both empty (cleared). Anything else is a `422` problem.
- Every API endpoint is bearer-auth, stateless JSON (iOS-viable, CLAUDE.md §Standing constraint).
- CLAUDE.md PHP rules apply to every PHP file: `final readonly`, no abbreviations, typed exceptions in `Exception/`, `Response::HTTP_*` never a number, no new comments unless they clear the bar, PHPMD-clean touched files.
- Frontend: standalone components, signals, sibling `.scss`, no hex colours or ad-hoc px, transloco keys in `public/i18n/en.json` **and** `de.json`.
- Commit format `type(#2176): lower-case summary`. No Claude attribution anywhere.
- Run frontend tests inside Docker: `docker compose exec -T frontend npx jest <path>`.

## File map

**Data (Task 1)**
- Create `backend/resources/feed-directory/snapshot.php` — one-off download-and-split script.
- Create (generated, committed) `backend/resources/feed-directory/{index.json,countries/*.json.gz,ABOUT.txt,LICENSE.txt,SOURCE}`.
- Create `frontend/src/app/settings/feed-directory/feed-directory-url-cases.json` — shared URL normalising cases.

**Backend**
- `src/Service/FeedDirectory/FeedDirectoryFiles.php` — resource layout, index reading, scope validation.
- `src/Service/FeedDirectory/Model/FeedDirectoryScopeModel.php` — the countries + languages pair.
- `src/Service/FeedDirectory/Model/SubscribedFeedsModel.php` — normalised URLs + hosts.
- `src/Service/FeedDirectory/Support/FeedDirectoryUrl.php` — the normalising rule.
- `src/Service/FeedDirectory/SubscribedFeeds.php` — user's subscriptions → `SubscribedFeedsModel`.
- `src/Service/FeedDirectory/Exception/{UnknownFeedDirectoryCountryException,InvalidFeedDirectoryScopeException}.php`.
- `src/Http/Factory/FeedDirectoryFileResponseFactory.php` — streams index and country files with ETag/304.
- `src/Http/SubscribedFeedsJson.php`.
- `src/Http/Problem/ExceptionProblems/FeedDirectoryProblems.php`.
- `src/Controller/Api/FeedDirectoryController.php`.
- `src/Dto/Me/UpdateFeedDirectoryScopeRequest.php`.
- Modify: `src/Entity/Preferences.php`, `src/Http/MeJson.php`, `src/Service/Account/AccountPreferencesWriter.php`, `src/Controller/Api/ReaderPreferencesController.php`, `src/Service/Backup/BackupLines.php`, `src/Service/Backup/Dto/AccountLine.php`, `src/Service/Backup/Pass/RestoreLoadPass.php`, `docs/backup.md`.
- Create `migrations/Version20261111120000.php`.
- Tests mirror `src/`; fixtures in `tests/Fixtures/feed-directory/`.

**Frontend** (all under `frontend/src/app/settings/feed-directory/` unless noted)
- `feed-directory.models.ts`, `feed-directory-api.ts`, `feed-directory-url.ts`, `feed-directory-rows.ts`, `feed-directory.store.ts`, `feed-directory-scope.service.ts`, `feed-directory-subscriber.ts`.
- `scope-picker/feed-directory-scope-picker.component.{ts,html,scss}`, `scope-dialog/feed-directory-scope-dialog.component.{ts,html,scss}`.
- `feed-directory-section.component.{ts,html,scss}`, `row/feed-directory-row.component.{ts,html,scss}`, `feeds-popover/feed-directory-feeds-popover.component.{ts,html,scss}`.
- Modify: `settings/settings-sections.ts`, `settings/settings.routes.ts`, `core/auth/auth.service.ts` (`UserPreferences`), `reader/feeds/add-feed/add-feed-dialog.component.{html,ts}`, `public/i18n/{en,de}.json`.
- Create `frontend/e2e/feed-directory.spec.ts`.

---

### Task 1: Snapshot script, committed data, shared URL cases

**Files:**
- Create: `backend/resources/feed-directory/snapshot.php`
- Create (generated): `backend/resources/feed-directory/index.json`, `countries/*.json.gz`, `ABOUT.txt`, `LICENSE.txt`, `SOURCE`
- Create: `frontend/src/app/settings/feed-directory/feed-directory-url-cases.json`

**Interfaces:**
- Produces: country file shape `{"country":"DE","categories":[string],"languages":[string],"domains":[[host, rank|null, avgLength|null, languageIndexes[], groups[], summary|null]]}` where a group is `[categoryIndex, isPodcast(0|1), feedUrls[]]` and indexes point into the file's own `categories` / `languages`.
- Produces: `index.json` shape `{"countries":[{"code":"DE","name":"Germany","sites":1329,"languages":{"de":1285,"en":61}}],"languages":{"de":1723,"en":12525}}` — `sites` counts domains; `languages` counts domains carrying that language (channels and podcasts together).

- [ ] **Step 1: Write the script**

```php
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
```

- [ ] **Step 2: Run it**

Run: `php backend/resources/feed-directory/snapshot.php`
Expected: `231 countries, 42020 domains` (exact numbers may move if upstream changed since 2026-10-15), no exception.

- [ ] **Step 3: Check the output**

Run:
```bash
ls backend/resources/feed-directory/countries | wc -l
du -sh backend/resources/feed-directory/countries
php -r '$i=json_decode(file_get_contents("backend/resources/feed-directory/index.json"),true); echo count($i["countries"]), " ", $i["languages"]["de"], PHP_EOL;'
gunzip -c backend/resources/feed-directory/countries/DE.json.gz | head -c 300
```
Expected: `231`; total around 3 MB; `231 1723`; JSON starting `{"country":"DE","categories":[`.

- [ ] **Step 4: Write the shared URL cases**

`frontend/src/app/settings/feed-directory/feed-directory-url-cases.json`:

```json
[
  { "url": "https://www.example.com/feed/", "normalised": "example.com/feed", "host": "example.com" },
  { "url": "http://example.com/feed", "normalised": "example.com/feed", "host": "example.com" },
  { "url": "HTTPS://Example.COM/Feed", "normalised": "example.com/Feed", "host": "example.com" },
  { "url": "https://example.com/", "normalised": "example.com", "host": "example.com" },
  { "url": "https://example.com", "normalised": "example.com", "host": "example.com" },
  { "url": "https://feeds.example.com/rss?x=1", "normalised": "feeds.example.com/rss?x=1", "host": "feeds.example.com" },
  { "url": "https://www.example.com/rss/?format=xml", "normalised": "example.com/rss/?format=xml", "host": "example.com" },
  { "url": "https://example.com:8443/feed", "normalised": "example.com:8443/feed", "host": "example.com" },
  { "url": "https://www2.example.com/feed", "normalised": "www2.example.com/feed", "host": "www2.example.com" }
]
```

The rule these encode: the scheme is dropped, the host lower-cased and one leading `www.` dropped; the path keeps its case; trailing `/`s are dropped only when no query follows (`/rss/?format=xml` keeps its slash); the query is kept; an explicit port stays in the normalised form but not in the host.

- [ ] **Step 5: Commit**

```bash
git add backend/resources/feed-directory frontend/src/app/settings/feed-directory/feed-directory-url-cases.json
git commit -m "feat(#2176): bundled feed directory snapshot split per country"
```

---

### Task 2: `FeedDirectoryFiles`, scope model and the two exceptions

**Files:**
- Create: `backend/src/Service/FeedDirectory/FeedDirectoryFiles.php`
- Create: `backend/src/Service/FeedDirectory/Model/FeedDirectoryScopeModel.php`
- Create: `backend/src/Service/FeedDirectory/Exception/UnknownFeedDirectoryCountryException.php`
- Create: `backend/src/Service/FeedDirectory/Exception/InvalidFeedDirectoryScopeException.php`
- Create: `backend/src/Http/Problem/ExceptionProblems/FeedDirectoryProblems.php`
- Create: `backend/tests/Fixtures/feed-directory/index.json`, `backend/tests/Fixtures/feed-directory/countries/DE.json.gz`, `.../AT.json.gz`
- Test: `backend/tests/Service/FeedDirectory/FeedDirectoryFilesTest.php`, `backend/tests/Service/FeedDirectory/Model/FeedDirectoryScopeModelTest.php`

**Interfaces:**
- Produces: `FeedDirectoryFiles::__construct(string $directory)` (autowired from `%kernel.project_dir%/resources/feed-directory`), `indexPath(): string`, `countryPath(string $countryCode): string` (throws `UnknownFeedDirectoryCountryException`), `countryCodes(): list<string>`, `languageCodes(): list<string>`, `requireKnown(FeedDirectoryScopeModel $scope): void` (throws `InvalidFeedDirectoryScopeException`).
- Produces: `FeedDirectoryScopeModel(list<string> $countries, list<string> $languages)` with `isEmpty(): bool`; its constructor throws `InvalidFeedDirectoryScopeException` when exactly one list is empty.

- [ ] **Step 1: Create the fixtures**

```bash
mkdir -p backend/tests/Fixtures/feed-directory/countries
cat > backend/tests/Fixtures/feed-directory/index.json <<'EOF'
{"countries":[{"code":"AT","name":"Austria","sites":1,"languages":{"de":1}},{"code":"DE","name":"Germany","sites":2,"languages":{"de":2,"en":1}}],"languages":{"de":3,"en":1}}
EOF
printf '%s' '{"country":"DE","categories":["Politics & Society"],"languages":["de","en"],"domains":[["example.de",10,800,[0,1],[[0,0,["https://example.de/feed"]]],"An example."],["second.de",null,null,[0],[[0,1,["https://second.de/podcast"]]],null]]}' | gzip -9 > backend/tests/Fixtures/feed-directory/countries/DE.json.gz
printf '%s' '{"country":"AT","categories":["Local News"],"languages":["de"],"domains":[["example.at",20,300,[0],[[0,0,["https://example.at/rss"]]],"Austrian example."]]}' | gzip -9 > backend/tests/Fixtures/feed-directory/countries/AT.json.gz
```

- [ ] **Step 2: Write the failing tests**

`backend/tests/Service/FeedDirectory/FeedDirectoryFilesTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\FeedDirectory;

use App\Service\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;
use App\Service\FeedDirectory\Exception\UnknownFeedDirectoryCountryException;
use App\Service\FeedDirectory\FeedDirectoryFiles;
use App\Service\FeedDirectory\Model\FeedDirectoryScopeModel;
use PHPUnit\Framework\TestCase;

final class FeedDirectoryFilesTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../Fixtures/feed-directory';

    private function files(): FeedDirectoryFiles
    {
        return new FeedDirectoryFiles(self::FIXTURES);
    }

    public function testItListsTheIndexedCountryCodes(): void
    {
        self::assertSame(['AT', 'DE'], $this->files()->countryCodes());
    }

    public function testItListsTheIndexedLanguageCodes(): void
    {
        self::assertSame(['de', 'en'], $this->files()->languageCodes());
    }

    public function testItResolvesAKnownCountryToItsFile(): void
    {
        self::assertSame(self::FIXTURES . '/countries/DE.json.gz', $this->files()->countryPath('DE'));
    }

    public function testItPointsAtTheIndex(): void
    {
        self::assertSame(self::FIXTURES . '/index.json', $this->files()->indexPath());
    }

    public function testAnUnknownCountryIsRejected(): void
    {
        $this->expectException(UnknownFeedDirectoryCountryException::class);

        $this->files()->countryPath('FR');
    }

    public function testACodeCarryingPathCharactersIsRejected(): void
    {
        $this->expectException(UnknownFeedDirectoryCountryException::class);

        $this->files()->countryPath('../index');
    }

    public function testALowerCaseCodeIsRejected(): void
    {
        $this->expectException(UnknownFeedDirectoryCountryException::class);

        $this->files()->countryPath('de');
    }

    public function testAKnownScopePasses(): void
    {
        $this->files()->requireKnown(new FeedDirectoryScopeModel(['DE', 'AT'], ['de']));

        $this->addToAssertionCount(1);
    }

    public function testAnEmptyScopePasses(): void
    {
        $this->files()->requireKnown(new FeedDirectoryScopeModel([], []));

        $this->addToAssertionCount(1);
    }

    public function testAnUnknownCountryInTheScopeIsRejected(): void
    {
        $this->expectException(InvalidFeedDirectoryScopeException::class);

        $this->files()->requireKnown(new FeedDirectoryScopeModel(['DE', 'FR'], ['de']));
    }

    public function testAnUnknownLanguageInTheScopeIsRejected(): void
    {
        $this->expectException(InvalidFeedDirectoryScopeException::class);

        $this->files()->requireKnown(new FeedDirectoryScopeModel(['DE'], ['xx']));
    }
}
```

`backend/tests/Service/FeedDirectory/Model/FeedDirectoryScopeModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\FeedDirectory\Model;

use App\Service\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;
use App\Service\FeedDirectory\Model\FeedDirectoryScopeModel;
use PHPUnit\Framework\TestCase;

final class FeedDirectoryScopeModelTest extends TestCase
{
    public function testBothListsEmptyIsAClearedScope(): void
    {
        self::assertTrue(new FeedDirectoryScopeModel([], [])->isEmpty());
    }

    public function testBothListsFilledIsAScope(): void
    {
        self::assertFalse(new FeedDirectoryScopeModel(['DE'], ['de'])->isEmpty());
    }

    public function testCountriesWithoutLanguagesAreRejected(): void
    {
        $this->expectException(InvalidFeedDirectoryScopeException::class);

        new FeedDirectoryScopeModel(['DE'], []);
    }

    public function testLanguagesWithoutCountriesAreRejected(): void
    {
        $this->expectException(InvalidFeedDirectoryScopeException::class);

        new FeedDirectoryScopeModel([], ['de']);
    }

    public function testRepeatedValuesCollapse(): void
    {
        $scope = new FeedDirectoryScopeModel(['DE', 'DE', 'AT'], ['de', 'de']);

        self::assertSame(['DE', 'AT'], $scope->countries);
        self::assertSame(['de'], $scope->languages);
    }
}
```

- [ ] **Step 3: Run to see them fail**

Run: `cd backend && php bin/phpunit tests/Service/FeedDirectory`
Expected: errors — classes not found.

- [ ] **Step 4: Implement**

`src/Service/FeedDirectory/Exception/UnknownFeedDirectoryCountryException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\FeedDirectory\Exception;

final class UnknownFeedDirectoryCountryException extends \RuntimeException
{
}
```

`src/Service/FeedDirectory/Exception/InvalidFeedDirectoryScopeException.php`: the same shape, class `InvalidFeedDirectoryScopeException`.

`src/Service/FeedDirectory/Model/FeedDirectoryScopeModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\FeedDirectory\Model;

use App\Service\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;

final readonly class FeedDirectoryScopeModel
{
    /** @var list<string> */
    public array $countries;

    /** @var list<string> */
    public array $languages;

    /**
     * @param list<string> $countries
     * @param list<string> $languages
     */
    public function __construct(array $countries, array $languages)
    {
        if (($countries === []) !== ($languages === [])) {
            throw new InvalidFeedDirectoryScopeException('Choose at least one country and one language, or neither.');
        }
        $this->countries = array_values(array_unique($countries));
        $this->languages = array_values(array_unique($languages));
    }

    public function isEmpty(): bool
    {
        return $this->countries === [];
    }
}
```

`src/Service/FeedDirectory/FeedDirectoryFiles.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\FeedDirectory;

use App\Service\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;
use App\Service\FeedDirectory\Exception\UnknownFeedDirectoryCountryException;
use App\Service\FeedDirectory\Model\FeedDirectoryScopeModel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FeedDirectoryFiles
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/feed-directory')]
        private string $directory,
    ) {
    }

    public function indexPath(): string
    {
        return $this->directory . '/index.json';
    }

    public function countryPath(string $countryCode): string
    {
        if (!\in_array($countryCode, $this->countryCodes(), true)) {
            throw new UnknownFeedDirectoryCountryException(\sprintf('No feed directory for country "%s".', $countryCode));
        }

        return \sprintf('%s/countries/%s.json.gz', $this->directory, $countryCode);
    }

    /** @return list<string> */
    public function countryCodes(): array
    {
        $codes = [];
        foreach ($this->entriesAt('countries') as $country) {
            if (\is_array($country) && \is_string($country['code'] ?? null)) {
                $codes[] = $country['code'];
            }
        }

        return $codes;
    }

    /** @return list<string> */
    public function languageCodes(): array
    {
        return array_values(array_filter(array_keys($this->entriesAt('languages')), is_string(...)));
    }

    public function requireKnown(FeedDirectoryScopeModel $scope): void
    {
        $unknownCountries = array_diff($scope->countries, $this->countryCodes());
        $unknownLanguages = array_diff($scope->languages, $this->languageCodes());
        if ($unknownCountries !== [] || $unknownLanguages !== []) {
            throw new InvalidFeedDirectoryScopeException(
                \sprintf('Unknown in the feed directory: %s.', implode(', ', [...$unknownCountries, ...$unknownLanguages])),
            );
        }
    }

    /** @return array<mixed> */
    private function entriesAt(string $key): array
    {
        $value = $this->index()[$key] ?? [];

        return \is_array($value) ? $value : [];
    }

    /** @return array<mixed> */
    private function index(): array
    {
        $decoded = json_decode((string) file_get_contents($this->indexPath()), true, flags: \JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
```

`countries` is a JSON list and `languages` a JSON object keyed by code; both decode to PHP arrays, so one reader serves both.

`src/Http/Problem/ExceptionProblems/FeedDirectoryProblems.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Problem\ExceptionProblems;

use App\Http\Problem\ApiProblem;
use App\Http\Problem\ResolvedProblem;
use App\Service\FeedDirectory\Exception\InvalidFeedDirectoryScopeException;
use App\Service\FeedDirectory\Exception\UnknownFeedDirectoryCountryException;
use Symfony\Component\HttpFoundation\Response;

final readonly class FeedDirectoryProblems implements ExceptionProblemsInterface
{
    public function resolve(\Throwable $exception): ?ResolvedProblem
    {
        return match (true) {
            $exception instanceof UnknownFeedDirectoryCountryException => new ResolvedProblem(new ApiProblem(
                'feed_directory_country_not_found',
                'Country not in the feed directory',
                Response::HTTP_NOT_FOUND,
                $exception->getMessage(),
            )),
            $exception instanceof InvalidFeedDirectoryScopeException => new ResolvedProblem(new ApiProblem(
                'feed_directory_scope_invalid',
                'Invalid feed directory scope',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage(),
            )),
            default => null,
        };
    }
}
```

Check how `ProblemCatalog` receives its mappers (`grep -n ExceptionProblemsInterface config/services.yaml src/Http/Problem/ProblemCatalog.php`); if they are collected by a tag through `_instanceof`, nothing else is needed, otherwise register the mapper the way `CatalogProblems` is.

- [ ] **Step 5: Run the tests**

Run: `cd backend && php bin/phpunit tests/Service/FeedDirectory`
Expected: PASS (16 tests).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/FeedDirectory backend/src/Http/Problem/ExceptionProblems/FeedDirectoryProblems.php backend/tests/Service/FeedDirectory backend/tests/Fixtures/feed-directory
git commit -m "feat(#2176): feed directory files and scope validation"
```

---

### Task 3: Index and country endpoints

**Files:**
- Create: `backend/src/Http/Factory/FeedDirectoryFileResponseFactory.php`
- Create: `backend/src/Controller/Api/FeedDirectoryController.php`
- Test: `backend/tests/Controller/Api/FeedDirectoryControllerTest.php`
- Modify: `backend/config/services_test.yaml` (or the test service config this repo uses — find it with `ls backend/config/*test*`) to point `FeedDirectoryFiles::$directory` at the fixtures.

**Interfaces:**
- Consumes: `FeedDirectoryFiles::indexPath()`, `countryPath()`.
- Produces: `GET /api/feed-directory/index` (route `api_feed_directory_index`), `GET /api/feed-directory/countries/{code}` (route `api_feed_directory_country`), and `FeedDirectoryFileResponseFactory::json(Request, string $path)`, `::gzippedJson(Request, string $path)`.

- [ ] **Step 1: Point the test kernel at the fixtures**

In the test service configuration:

```yaml
    App\Service\FeedDirectory\FeedDirectoryFiles:
        arguments:
            $directory: '%kernel.project_dir%/tests/Fixtures/feed-directory'
```

- [ ] **Step 2: Write the failing functional test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Tests\Support\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

final class FeedDirectoryControllerTest extends ApiTestCase
{
    public function testTheIndexListsTheCountries(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'directory-index@example.test');

        $client->request('GET', '/api/feed-directory/index');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['AT', 'DE'], array_column($this->payload($client)['countries'], 'code'));
    }

    public function testACountryFileIsStreamedGzipped(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'directory-country@example.test');

        $client->request('GET', '/api/feed-directory/countries/DE');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Encoding', 'gzip');
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHasHeader('ETag');
        $body = gzdecode((string) $client->getInternalResponse()->getContent());
        self::assertIsString($body);
        self::assertStringStartsWith('{"country":"DE"', $body);
    }

    public function testAMatchingETagAnswersNotModified(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'directory-etag@example.test');
        $client->request('GET', '/api/feed-directory/countries/DE');
        $etag = (string) $client->getResponse()->headers->get('ETag');

        $client->request('GET', '/api/feed-directory/countries/DE', server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
    }

    public function testAnUnknownCountryIsNotFound(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'directory-unknown@example.test');

        $client->request('GET', '/api/feed-directory/countries/FR');

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
    }

    public function testASignedOutClientIsRejected(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/feed-directory/index');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }
}
```

Read `ApiTestCase::payload()` and `assertRejected()` first; if `payload()` returns `array<string, mixed>`, narrow `countries` with `self::assertIsArray()` before `array_column` to keep PHPStan at max.

- [ ] **Step 3: Run to see it fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/FeedDirectoryControllerTest.php`
Expected: FAIL — 404 for the routes.

- [ ] **Step 4: Implement the response factory**

```php
<?php

declare(strict_types=1);

namespace App\Http\Factory;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class FeedDirectoryFileResponseFactory
{
    private const int MAX_AGE_SECONDS = 86400;

    public function json(Request $request, string $path): BinaryFileResponse
    {
        return $this->cached($request, new BinaryFileResponse($path, headers: ['Content-Type' => 'application/json']));
    }

    public function gzippedJson(Request $request, string $path): BinaryFileResponse
    {
        return $this->cached($request, new BinaryFileResponse($path, headers: [
            'Content-Type' => 'application/json',
            'Content-Encoding' => 'gzip',
        ]));
    }

    private function cached(Request $request, BinaryFileResponse $response): BinaryFileResponse
    {
        $response->setAutoEtag();
        $response->setPrivate();
        $response->setMaxAge(self::MAX_AGE_SECONDS);
        $response->isNotModified($request);

        return $response;
    }
}
```

- [ ] **Step 5: Implement the controller**

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\Factory\FeedDirectoryFileResponseFactory;
use App\Service\FeedDirectory\FeedDirectoryFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/feed-directory')]
final readonly class FeedDirectoryController
{
    public function __construct(
        private FeedDirectoryFiles $files,
        private FeedDirectoryFileResponseFactory $responses,
    ) {
    }

    #[Route('/index', name: 'api_feed_directory_index', methods: ['GET'])]
    public function index(Request $request): BinaryFileResponse
    {
        return $this->responses->json($request, $this->files->indexPath());
    }

    #[Route('/countries/{code}', name: 'api_feed_directory_country', methods: ['GET'])]
    public function country(Request $request, string $code): BinaryFileResponse
    {
        return $this->responses->gzippedJson($request, $this->files->countryPath($code));
    }
}
```

`/api` already requires a signed-in user through the firewall; confirm `security.yaml` has no `PUBLIC_ACCESS` rule matching `/api/feed-directory`.

- [ ] **Step 6: Run the tests**

Run: `cd backend && php bin/phpunit tests/Controller/Api/FeedDirectoryControllerTest.php`
Expected: PASS (5 tests). If `Content-Type` comes back as `application/json; charset=…` or the guessed type, set it after construction with `$response->headers->set(...)` instead.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Http/Factory/FeedDirectoryFileResponseFactory.php backend/src/Controller/Api/FeedDirectoryController.php backend/tests/Controller/Api/FeedDirectoryControllerTest.php backend/config
git commit -m "feat(#2176): stream feed directory index and country files"
```

---

### Task 4: URL normaliser and the subscribed endpoint

**Files:**
- Create: `backend/src/Service/FeedDirectory/Support/FeedDirectoryUrl.php`
- Create: `backend/src/Service/FeedDirectory/Model/SubscribedFeedsModel.php`
- Create: `backend/src/Service/FeedDirectory/SubscribedFeeds.php`
- Create: `backend/src/Http/SubscribedFeedsJson.php`
- Modify: `backend/src/Controller/Api/FeedDirectoryController.php`
- Test: `backend/tests/Service/FeedDirectory/Support/FeedDirectoryUrlTest.php`, extend `FeedDirectoryControllerTest.php`

**Interfaces:**
- Consumes: `FeedRepository::subscribedUrlSetForUser(int $userId): array<string, true>`; the shared cases JSON from Task 1.
- Produces: `FeedDirectoryUrl::normalise(string $url): string`, `FeedDirectoryUrl::host(string $url): string`; `SubscribedFeeds::of(int $userId): SubscribedFeedsModel` with `list<string> $urls`, `list<string> $hosts`; `GET /api/feed-directory/subscribed` → `{"urls": [...], "hosts": [...]}`.

- [ ] **Step 1: Write the failing normaliser test against the shared table**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\FeedDirectory\Support;

use App\Service\FeedDirectory\Support\FeedDirectoryUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeedDirectoryUrlTest extends TestCase
{
    private const string CASES = __DIR__ . '/../../../../../frontend/src/app/settings/feed-directory/feed-directory-url-cases.json';

    /** @return iterable<string, array{string, string, string}> */
    public static function sharedCases(): iterable
    {
        $cases = json_decode((string) file_get_contents(self::CASES), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($cases)) {
            throw new \UnexpectedValueException('The shared URL case table is not a list.');
        }
        foreach ($cases as $case) {
            if (!\is_array($case) || !\is_string($case['url'] ?? null) || !\is_string($case['normalised'] ?? null) || !\is_string($case['host'] ?? null)) {
                throw new \UnexpectedValueException('A shared URL case lacks url, normalised or host.');
            }
            yield $case['url'] => [$case['url'], $case['normalised'], $case['host']];
        }
    }

    #[DataProvider('sharedCases')]
    public function testItNormalisesLikeTheFrontend(string $url, string $normalised, string $host): void
    {
        self::assertSame($normalised, FeedDirectoryUrl::normalise($url));
        self::assertSame($host, FeedDirectoryUrl::host($url));
    }
}
```

- [ ] **Step 2: Run to see it fail**

Run: `cd backend && php bin/phpunit tests/Service/FeedDirectory/Support`
Expected: errors — class not found.

- [ ] **Step 3: Implement the normaliser**

```php
<?php

declare(strict_types=1);

namespace App\Service\FeedDirectory\Support;

final class FeedDirectoryUrl
{
    public static function normalise(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false) {
            return strtolower($url);
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        if ($query === '') {
            $path = rtrim($path, '/');
        }

        return self::hostOf($parts) . $port . $path . $query;
    }

    public static function host(string $url): string
    {
        $parts = parse_url(trim($url));

        return $parts === false ? '' : self::hostOf($parts);
    }

    /** @param array<string, int|string> $parts */
    private static function hostOf(array $parts): string
    {
        $host = strtolower((string) ($parts['host'] ?? ''));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
```

Check this against the case table: `https://example.com/` → path `/` → `''` → `example.com`. ✓ `https://www.example.com/rss/?format=xml` keeps `/rss/` because a query follows. ✓

- [ ] **Step 4: Run the normaliser test**

Run: `cd backend && php bin/phpunit tests/Service/FeedDirectory/Support`
Expected: PASS (9 cases).

- [ ] **Step 5: Write the failing endpoint test** (append to `FeedDirectoryControllerTest`)

Find how other tests subscribe a user to a feed (`grep -rn "new Subscription(" backend/tests | head`) and use the same helper. Then:

```php
    public function testSubscribedFeedsComeBackNormalisedWithTheirHosts(): void
    {
        $client = self::createClient();
        $user = $this->signIn($client, 'directory-subscribed@example.test');
        $this->subscribe($user, 'https://www.example.de/feed/');
        $this->subscribe($user, 'http://podcasts.example.at/rss');

        $client->request('GET', '/api/feed-directory/subscribed');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['urls' => ['example.de/feed', 'podcasts.example.at/rss'], 'hosts' => ['example.de', 'podcasts.example.at']],
            $this->payload($client),
        );
    }

    public function testAnotherUsersSubscriptionsAreNotListed(): void
    {
        $client = self::createClient();
        $other = $this->factory()->create('directory-other@example.test');
        $this->subscribe($other, 'https://example.de/feed');
        $this->signIn($client, 'directory-mine@example.test');

        $client->request('GET', '/api/feed-directory/subscribed');

        self::assertSame(['urls' => [], 'hosts' => []], $this->payload($client));
    }
```

`subscribe(User, string $url)` is a private helper you write in this test with the persistence code the found tests use. Sort order: the service sorts both lists, so the assertion order is alphabetical.

- [ ] **Step 6: Implement the model, service, mapper and action**

`Model/SubscribedFeedsModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\FeedDirectory\Model;

final readonly class SubscribedFeedsModel
{
    /**
     * @param list<string> $urls
     * @param list<string> $hosts
     */
    public function __construct(
        public array $urls,
        public array $hosts,
    ) {
    }
}
```

`SubscribedFeeds.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\FeedDirectory;

use App\Repository\FeedRepository;
use App\Service\FeedDirectory\Model\SubscribedFeedsModel;
use App\Service\FeedDirectory\Support\FeedDirectoryUrl;

final readonly class SubscribedFeeds
{
    public function __construct(
        private FeedRepository $feeds,
    ) {
    }

    public function of(int $userId): SubscribedFeedsModel
    {
        $urls = array_keys($this->feeds->subscribedUrlSetForUser($userId));

        return new SubscribedFeedsModel(
            self::sortedUnique(array_map(FeedDirectoryUrl::normalise(...), $urls)),
            self::sortedUnique(array_map(FeedDirectoryUrl::host(...), $urls)),
        );
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sortedUnique(array $values): array
    {
        $unique = array_values(array_unique($values));
        sort($unique);

        return $unique;
    }
}
```

If `PersistenceKnowsNoServiceRule` or a consumer-owned-interface convention requires it, inject through an interface the module owns instead of `FeedRepository` directly — check how `CatalogController`'s use of `subscribedUrlSetForUser` was reviewed (`git log -S subscribedUrlSetForUser --oneline`).

`src/Http/SubscribedFeedsJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\FeedDirectory\Model\SubscribedFeedsModel;

final class SubscribedFeedsJson
{
    /** @return array{urls: list<string>, hosts: list<string>} */
    public static function of(SubscribedFeedsModel $subscribed): array
    {
        return ['urls' => $subscribed->urls, 'hosts' => $subscribed->hosts];
    }
}
```

Controller action (add `SubscribedFeeds $subscribed` to the constructor):

```php
    #[Route('/subscribed', name: 'api_feed_directory_subscribed', methods: ['GET'])]
    public function subscribed(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse(SubscribedFeedsJson::of($this->subscribed->of($user->requireId())));
    }
```

- [ ] **Step 7: Run the backend tests for the module**

Run: `cd backend && php bin/phpunit tests/Service/FeedDirectory tests/Controller/Api/FeedDirectoryControllerTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#2176): subscribed feeds for the directory, normalised like the frontend"
```

---

### Task 5: The scope preference, end to end in the backend

**Files:**
- Create: `backend/migrations/Version20261111120000.php`
- Create: `backend/src/Dto/Me/UpdateFeedDirectoryScopeRequest.php`
- Modify: `backend/src/Entity/Preferences.php`, `backend/src/Http/MeJson.php`, `backend/src/Service/Account/AccountPreferencesWriter.php`, `backend/src/Controller/Api/ReaderPreferencesController.php`
- Modify (backup): `backend/src/Service/Backup/BackupLines.php`, `backend/src/Service/Backup/Dto/AccountLine.php`, `backend/src/Service/Backup/Pass/RestoreLoadPass.php`, `backend/tests/Support/BackupFieldDeclarations.php`, `backend/tests/Support/FullyPopulatedAccount.php`, `docs/backup.md`
- Test: `backend/tests/Controller/Api/ReaderPreferencesControllerTest.php`, `backend/tests/Controller/Api/MeControllerTest.php`, `backend/tests/Controller/Api/MeRoutesTest.php`, `backend/tests/Service/Backup/Dto/AccountLineTest.php`, `backend/tests/Service/Account/AccountPreferencesWriterTest.php`

**Interfaces:**
- Consumes: `FeedDirectoryScopeModel`, `FeedDirectoryFiles::requireKnown()`.
- Produces: `Preferences::getFeedDirectoryCountries(): list<string>`, `getFeedDirectoryLanguages(): list<string>`, `setFeedDirectoryScope(array $countries, array $languages): void` — plain lists, because an entity must not import a Service value (`PersistenceKnowsNoServiceRule`); `AccountPreferencesWriter::changeFeedDirectoryScope(User $user, FeedDirectoryScopeModel $scope): void`; `PATCH /api/me/feed-directory-scope` (route `api_me_update_feed_directory_scope`); `/api/me` `preferences.feedDirectoryScope = {countries: [...], languages: [...]}`.

Use `git show 3c82fb843` (the trending preference) as the template for every touched file in this task; the steps below name what differs.

- [ ] **Step 1: Write the failing controller tests** (append to `ReaderPreferencesControllerTest`)

```php
    private function patchScope(KernelBrowser $client, string $body): void
    {
        $client->request(
            'PATCH',
            '/api/me/feed-directory-scope',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );
    }

    /** @return array<mixed> */
    private function scope(KernelBrowser $client): array
    {
        $preferences = $this->payload($client)['preferences'];
        self::assertIsArray($preferences);
        self::assertIsArray($preferences['feedDirectoryScope']);

        return $preferences['feedDirectoryScope'];
    }

    public function testANewAccountHasNoFeedDirectoryScope(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-new@example.test');

        $client->request('GET', '/api/me');

        self::assertSame(['countries' => [], 'languages' => []], $this->scope($client));
    }

    public function testAScopeIsSavedAndReadBack(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-save@example.test');

        $this->patchScope($client, '{"countries":["DE","AT"],"languages":["de"]}');

        self::assertResponseIsSuccessful();
        self::assertSame(['countries' => ['DE', 'AT'], 'languages' => ['de']], $this->scope($client));
    }

    public function testTwoEmptyListsClearTheScope(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-clear@example.test');
        $this->patchScope($client, '{"countries":["DE"],"languages":["de"]}');

        $this->patchScope($client, '{"countries":[],"languages":[]}');

        self::assertSame(['countries' => [], 'languages' => []], $this->scope($client));
    }

    public function testCountriesWithoutLanguagesAreRejected(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-half@example.test');

        $this->patchScope($client, '{"countries":["DE"],"languages":[]}');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnUnknownCountryIsRejected(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-unknown@example.test');

        $this->patchScope($client, '{"countries":["FR"],"languages":["de"]}');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnUnknownLanguageIsRejected(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-language@example.test');

        $this->patchScope($client, '{"countries":["DE"],"languages":["xx"]}');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnEmptyBodyIsRejectedRatherThanClearingTheScope(): void
    {
        $client = self::createClient();
        $this->signIn($client, 'scope-empty@example.test');

        $this->patchScope($client, '{}');

        $this->assertRejected($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }
```

Also add `'feedDirectoryScope' => ['countries' => [], 'languages' => []],` after `'trendingEnabled' => true,` in the full-preferences assertions at `ReaderPreferencesControllerTest.php:51` and `MeControllerTest.php:169`, and the route to `MeRoutesTest::ROUTES`:

```php
        'api_me_update_feed_directory_scope' => [
            'PATCH',
            '/api/me/feed-directory-scope',
            ReaderPreferencesController::class . '::updateFeedDirectoryScope',
        ],
```

- [ ] **Step 2: Run to see them fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/ReaderPreferencesControllerTest.php tests/Controller/Api/MeControllerTest.php tests/Controller/Api/MeRoutesTest.php`
Expected: FAIL.

- [ ] **Step 3: Migration**

`migrations/Version20261111120000.php`, modelled on `Version20261105110000.php` (skip guards, MySQL and SQLite branches, `assertSqlite()`):

```php
    public function getDescription(): string
    {
        return 'Records the countries and languages the feed directory shows (#2176).';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(
            $schema->getTable('user_preferences')->hasColumn('feed_directory_countries'),
            'user_preferences.feed_directory_countries already exists.',
        );

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->addSql('ALTER TABLE user_preferences ADD feed_directory_countries JSON DEFAULT NULL, ADD feed_directory_languages JSON DEFAULT NULL');

            return;
        }

        $this->assertSqlite();
        $this->addSql('ALTER TABLE user_preferences ADD COLUMN feed_directory_countries CLOB DEFAULT NULL');
        $this->addSql('ALTER TABLE user_preferences ADD COLUMN feed_directory_languages CLOB DEFAULT NULL');
    }
```

`down()` drops both columns the same way. Compare the SQLite type with what `hidden_share_targets` got (`grep -rn hidden_share_targets backend/migrations`) and copy it exactly; `doctrine:schema:validate` must stay clean.

- [ ] **Step 4: Entity**

In `Preferences`, beside `hiddenShareTargets`:

```php
    /** @var list<string>|null */
    #[ORM\Column(name: 'feed_directory_countries', type: Types::JSON, nullable: true)]
    private ?array $feedDirectoryCountries = null;

    /** @var list<string>|null */
    #[ORM\Column(name: 'feed_directory_languages', type: Types::JSON, nullable: true)]
    private ?array $feedDirectoryLanguages = null;
```

and, with the other accessors:

```php
    /** @return list<string> */
    public function getFeedDirectoryCountries(): array
    {
        return $this->feedDirectoryCountries ?? [];
    }

    /** @return list<string> */
    public function getFeedDirectoryLanguages(): array
    {
        return $this->feedDirectoryLanguages ?? [];
    }

    /**
     * @param list<string> $countries
     * @param list<string> $languages
     */
    public function setFeedDirectoryScope(array $countries, array $languages): void
    {
        $this->feedDirectoryCountries = $countries;
        $this->feedDirectoryLanguages = $languages;
    }
```

- [ ] **Step 5: Writer, DTO, controller action, profile**

`AccountPreferencesWriter` gains `FeedDirectoryFiles $feedDirectory` in its constructor and:

```php
    public function changeFeedDirectoryScope(User $user, FeedDirectoryScopeModel $scope): void
    {
        $this->feedDirectory->requireKnown($scope);
        $user->getPreferences()->setFeedDirectoryScope($scope->countries, $scope->languages);
        $this->entityManager->flush();
    }
```

`src/Dto/Me/UpdateFeedDirectoryScopeRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Me;

use App\Service\FeedDirectory\Model\FeedDirectoryScopeModel;
use Symfony\Component\Validator\Constraints as Assert;

/** No defaults: an empty body must never clear the scope. */
final readonly class UpdateFeedDirectoryScopeRequest
{
    private const int MAX_ENTRIES = 300;

    /**
     * @param list<string> $countries
     * @param list<string> $languages
     */
    public function __construct(
        #[Assert\Count(max: self::MAX_ENTRIES)]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[A-Z]{2}$/')])]
        public array $countries,
        #[Assert\Count(max: self::MAX_ENTRIES)]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[a-z]{2,3}$/')])]
        public array $languages,
    ) {
    }

    public function toScope(): FeedDirectoryScopeModel
    {
        return new FeedDirectoryScopeModel($this->countries, $this->languages);
    }
}
```

Controller action in `ReaderPreferencesController`:

```php
    /** Its own PATCH for the reason updatePreferences() gives. */
    #[Route('/api/me/feed-directory-scope', name: 'api_me_update_feed_directory_scope', methods: ['PATCH'])]
    public function updateFeedDirectoryScope(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateFeedDirectoryScopeRequest $request,
    ): JsonResponse {
        $this->preferences->changeFeedDirectoryScope($user, $request->toScope());

        return new JsonResponse($this->profile->of($user));
    }
```

`MeJson::profile()` preferences block, after `'trendingEnabled'`:

```php
                'feedDirectoryScope' => [
                    'countries' => $preferences->getFeedDirectoryCountries(),
                    'languages' => $preferences->getFeedDirectoryLanguages(),
                ],
```

- [ ] **Step 6: Writer unit test** (in `AccountPreferencesWriterTest`, following its existing trending test): `changeFeedDirectoryScope` stores both lists and flushes; with an unknown code it throws `InvalidFeedDirectoryScopeException` and the preferences stay as they were. Build `FeedDirectoryFiles` on the fixtures directory.

- [ ] **Step 7: Backup round trip**

- `BackupLines`: after `'trendingEnabled' => …`: `'feedDirectoryCountries' => $user->getPreferences()->getFeedDirectoryCountries(), 'feedDirectoryLanguages' => $user->getPreferences()->getFeedDirectoryLanguages(),`
- `AccountLine`: two `public array` fields (`@param list<string>`), read with `LineFieldWithDefault::stringList($line, 'feedDirectoryCountries')` / `…Languages`.
- `RestoreLoadPass`: `$this->user->getPreferences()->setFeedDirectoryScope($line->feedDirectoryCountries, $line->feedDirectoryLanguages);` — no index validation on restore, the same as hidden share targets.
- `BackupFieldDeclarations`: `'feedDirectoryCountries' => 'feedDirectoryCountries', 'feedDirectoryLanguages' => 'feedDirectoryLanguages',` under `Preferences::class`.
- `FullyPopulatedAccount`: `$user->getPreferences()->setFeedDirectoryScope(['DE', 'AT'], ['de']);`
- `AccountLineTest`: `testAnOlderBackupHasNoFeedDirectoryScope` (both lists `[]`) and `testItReadsTheFeedDirectoryScope`.
- `docs/backup.md` `account` row: add "the countries and languages the feed directory shows (`feedDirectoryCountries`, `feedDirectoryLanguages`; a file without them restores an empty scope)," after the Trending clause.

- [ ] **Step 8: Run the touched suites**

Run: `cd backend && php bin/phpunit tests/Controller/Api tests/Service/Backup tests/Service/Account tests/Entity`
Expected: PASS.

- [ ] **Step 9: Migration check**

Run: `cd backend && rm -f var/migrate-check.db && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" php bin/console doctrine:migrations:migrate -n && DATABASE_URL="sqlite:///%kernel.project_dir%/var/migrate-check.db" php bin/console doctrine:schema:validate`
Expected: migrations run, schema "in sync". Then `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction` to apply it to the live Docker DB.

- [ ] **Step 10: Commit**

```bash
git add backend docs/backup.md
git commit -m "feat(#2176): feed directory scope preference with backup"
```

---

### Task 6: Frontend models, API client and URL normaliser

**Files:**
- Create: `frontend/src/app/settings/feed-directory/feed-directory.models.ts`
- Create: `frontend/src/app/settings/feed-directory/feed-directory-api.ts` (+ `.spec.ts`)
- Create: `frontend/src/app/settings/feed-directory/feed-directory-url.ts` (+ `.spec.ts`)
- Modify: `frontend/src/app/core/auth/auth.service.ts` (`UserPreferences`)

**Interfaces:**
- Produces (models):

```ts
export interface FeedDirectoryScope {
  countries: readonly string[];
  languages: readonly string[];
}

export interface FeedDirectoryIndexCountry {
  code: string;
  name: string;
  sites: number;
  languages: Readonly<Record<string, number>>;
}

export interface FeedDirectoryIndex {
  countries: readonly FeedDirectoryIndexCountry[];
  languages: Readonly<Record<string, number>>;
}

/** [categoryIndex, isPodcast, feedUrls] */
export type FeedDirectoryGroupTuple = readonly [number, 0 | 1, readonly string[]];

/** [host, rank, avgLength, languageIndexes, groups, summary] */
export type FeedDirectoryDomainTuple = readonly [
  string,
  number | null,
  number | null,
  readonly number[],
  readonly FeedDirectoryGroupTuple[],
  string | null,
];

export interface FeedDirectoryCountryFile {
  country: string;
  categories: readonly string[];
  languages: readonly string[];
  domains: readonly FeedDirectoryDomainTuple[];
}

export interface FeedDirectorySubscribed {
  urls: readonly string[];
  hosts: readonly string[];
}
```

- Produces (API): `FeedDirectoryApi.index(): Observable<FeedDirectoryIndex>`, `.country(code: string): Observable<FeedDirectoryCountryFile>`, `.subscribed(): Observable<FeedDirectorySubscribed>`, `.saveScope(scope: FeedDirectoryScope): Observable<CurrentUser>`.
- Produces: `feedDirectoryUrl(url: string): string`, `feedDirectoryHost(url: string): string`.
- Modifies: `UserPreferences.feedDirectoryScope?: FeedDirectoryScope` (optional: a cached profile from before the deploy lacks it).

- [ ] **Step 1: Failing tests**

`feed-directory-url.spec.ts`:

```ts
import cases from './feed-directory-url-cases.json';
import { feedDirectoryHost, feedDirectoryUrl } from './feed-directory-url';

describe('feedDirectoryUrl', () => {
  it.each(cases)('normalises $url like the backend', ({ url, normalised, host }) => {
    expect(feedDirectoryUrl(url)).toBe(normalised);
    expect(feedDirectoryHost(url)).toBe(host);
  });
});
```

If `resolveJsonModule` is off in `tsconfig.spec.json`, turn it on there (spec config only).

`feed-directory-api.spec.ts`, following `catalog-api.spec.ts`: one test per method asserting URL and method (`GET https://api.test/api/feed-directory/index`, `GET …/countries/DE`, `GET …/subscribed`, `PATCH https://api.test/api/me/feed-directory-scope` with body `{ countries: ['DE'], languages: ['de'] }`).

- [ ] **Step 2: Run to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory`
Expected: FAIL — modules not found.

- [ ] **Step 3: Implement**

`feed-directory-url.ts`:

```ts
/** The backend's FeedDirectoryUrl rule; feed-directory-url-cases.json keeps the two in step. */
export function feedDirectoryUrl(url: string): string {
  const parsed = parse(url);
  if (!parsed) return url.toLowerCase();
  const path = parsed.search ? parsed.pathname : parsed.pathname.replace(/\/+$/, '');
  const port = parsed.port ? `:${parsed.port}` : '';
  return `${hostOf(parsed)}${port}${path}${parsed.search}`;
}

export function feedDirectoryHost(url: string): string {
  const parsed = parse(url);
  return parsed ? hostOf(parsed) : '';
}

function parse(url: string): URL | null {
  try {
    return new URL(url.trim());
  } catch {
    return null;
  }
}

function hostOf(url: URL): string {
  return url.hostname.replace(/^www\./, '');
}
```

`URL` lower-cases the host and drops the default port (`:443`), matching PHP's `parse_url` for these cases; `https://example.com` has pathname `/`, which the trailing-slash rule removes.

`feed-directory-api.ts`, following `CatalogApi`:

```ts
@Injectable({ providedIn: 'root' })
export class FeedDirectoryApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  index(): Observable<FeedDirectoryIndex> {
    return this.http.get<FeedDirectoryIndex>(`${this.base}/api/feed-directory/index`);
  }

  country(code: string): Observable<FeedDirectoryCountryFile> {
    return this.http.get<FeedDirectoryCountryFile>(
      `${this.base}/api/feed-directory/countries/${encodeURIComponent(code)}`,
    );
  }

  subscribed(): Observable<FeedDirectorySubscribed> {
    return this.http.get<FeedDirectorySubscribed>(`${this.base}/api/feed-directory/subscribed`);
  }

  saveScope(scope: FeedDirectoryScope): Observable<CurrentUser> {
    return this.http.patch<CurrentUser>(`${this.base}/api/me/feed-directory-scope`, scope);
  }
}
```

- [ ] **Step 4: Run the tests**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/feed-directory frontend/src/app/core/auth/auth.service.ts frontend/tsconfig.spec.json
git commit -m "feat(#2176): feed directory api client and url normaliser"
```

---

### Task 7: Rows — prepare, filter, sort, count (pure functions)

**Files:**
- Create: `frontend/src/app/settings/feed-directory/feed-directory-rows.ts` (+ `.spec.ts`)

**Interfaces:**
- Consumes: Task 6 models, `feedDirectoryUrl`, `feedDirectoryHost`.
- Produces:

```ts
export type FeedDirectoryMode = 'channels' | 'podcasts';
export type FeedDirectoryLength = 'headlines' | 'summaries' | 'long';
export type FeedDirectorySort = 'top' | 'az' | 'feeds';

export interface FeedDirectoryFeed {
  readonly url: string;
  readonly category: string;
  readonly podcast: boolean;
  readonly subscribed: boolean;
}

export interface FeedDirectoryRow {
  readonly host: string;
  readonly country: string;
  readonly rank: number | null;
  readonly length: FeedDirectoryLength | null;
  readonly languages: readonly string[];
  readonly summary: string | null;
  readonly feeds: readonly FeedDirectoryFeed[];
  readonly following: boolean;
  readonly searchText: string;
}

export interface FeedDirectoryFilter {
  readonly mode: FeedDirectoryMode;
  readonly country: string | null;
  readonly categories: ReadonlySet<string>;
  readonly lengths: ReadonlySet<FeedDirectoryLength>;
  readonly languages: ReadonlySet<string>;
  readonly query: string;
  readonly sort: FeedDirectorySort;
}

export function prepareRows(file: FeedDirectoryCountryFile, scopeLanguages: ReadonlySet<string>, subscribed: FeedDirectorySubscribed): FeedDirectoryRow[];
export function feedsIn(row: FeedDirectoryRow, mode: FeedDirectoryMode): FeedDirectoryFeed[];
export function filterRows(rows: readonly FeedDirectoryRow[], filter: FeedDirectoryFilter): FeedDirectoryRow[];
export function countByCountry(rows: readonly FeedDirectoryRow[], filter: FeedDirectoryFilter): Map<string, number>;
export function lengthOf(averageLength: number | null): FeedDirectoryLength | null;
export function withSubscribed(rows: readonly FeedDirectoryRow[], feedUrl: string): FeedDirectoryRow[];
```

- [ ] **Step 1: Write the failing tests**

```ts
import { FeedDirectoryCountryFile } from './feed-directory.models';
import {
  FeedDirectoryFilter,
  countByCountry,
  feedsIn,
  filterRows,
  lengthOf,
  prepareRows,
  withSubscribed,
} from './feed-directory-rows';

const DE: FeedDirectoryCountryFile = {
  country: 'DE',
  categories: ['Politics & Society', 'Sports'],
  languages: ['de', 'en'],
  domains: [
    ['zeit.de', 998, 1770, [0], [[0, 0, ['https://www.zeit.de/politik/index']], [1, 1, ['https://zeit.de/podcast']]], 'Weekly newspaper.'],
    ['example.de', null, 300, [1], [[1, 0, ['https://example.de/feed']]], 'English only.'],
    ['heise.de', 776, 1334, [0, 1], [[0, 0, ['https://www.heise.de/rss/heise.rdf', 'https://www.heise.de/security/rss']]], 'IT news.'],
    ['b.de', null, null, [0], [[1, 0, ['https://b.de/feed']]], null],
  ],
};
const SUBSCRIBED = { urls: ['heise.de/rss/heise.rdf'], hosts: ['heise.de'] };
const ALL: FeedDirectoryFilter = {
  mode: 'channels',
  country: null,
  categories: new Set(),
  lengths: new Set(),
  languages: new Set(),
  query: '',
  sort: 'top',
};

describe('feed directory rows', () => {
  const rows = prepareRows(DE, new Set(['de']), SUBSCRIBED);

  it('drops domains with none of the scoped languages', () => {
    expect(rows.map((row) => row.host)).toEqual(['zeit.de', 'heise.de', 'b.de']);
  });

  it('marks a subscribed feed by its normalised url and the row as followed by host', () => {
    const heise = rows.find((row) => row.host === 'heise.de')!;
    expect(heise.following).toBe(true);
    expect(heise.feeds.map((feed) => feed.subscribed)).toEqual([true, false]);
  });

  it('follows a subdomain host too', () => {
    const [row] = prepareRows(DE, new Set(['de']), { urls: [], hosts: ['feeds.zeit.de'] });
    expect(row.following).toBe(true);
  });

  it('splits channels from podcasts', () => {
    const zeit = rows[0];
    expect(feedsIn(zeit, 'channels').map((feed) => feed.url)).toEqual(['https://www.zeit.de/politik/index']);
    expect(feedsIn(zeit, 'podcasts').map((feed) => feed.url)).toEqual(['https://zeit.de/podcast']);
  });

  it('buckets lengths like InfoBubble', () => {
    expect([lengthOf(499), lengthOf(500), lengthOf(2499), lengthOf(2500), lengthOf(null)]).toEqual([
      'headlines',
      'summaries',
      'summaries',
      'long',
      null,
    ]);
  });

  it('sorts by rank with unranked last, then host', () => {
    expect(filterRows(rows, ALL).map((row) => row.host)).toEqual(['heise.de', 'zeit.de', 'b.de']);
  });

  it('sorts a to z', () => {
    expect(filterRows(rows, { ...ALL, sort: 'az' }).map((row) => row.host)).toEqual(['b.de', 'heise.de', 'zeit.de']);
  });

  it('sorts by feed count in the current mode, then rank', () => {
    expect(filterRows(rows, { ...ALL, sort: 'feeds' }).map((row) => row.host)).toEqual(['heise.de', 'zeit.de', 'b.de']);
  });

  it('hides a domain without feeds in the mode', () => {
    expect(filterRows(rows, { ...ALL, mode: 'podcasts' }).map((row) => row.host)).toEqual(['zeit.de']);
  });

  it('filters by category within the mode', () => {
    expect(filterRows(rows, { ...ALL, categories: new Set(['Sports']) }).map((row) => row.host)).toEqual(['b.de']);
  });

  it('filters by length', () => {
    expect(filterRows(rows, { ...ALL, lengths: new Set(['long']) })).toEqual([]);
    expect(filterRows(rows, { ...ALL, lengths: new Set(['summaries']) }).map((row) => row.host)).toEqual(['heise.de', 'zeit.de']);
  });

  it('filters by language', () => {
    expect(filterRows(rows, { ...ALL, languages: new Set(['en']) }).map((row) => row.host)).toEqual(['heise.de']);
  });

  it('searches host and summary, case-insensitively', () => {
    expect(filterRows(rows, { ...ALL, query: 'WEEKLY' }).map((row) => row.host)).toEqual(['zeit.de']);
    expect(filterRows(rows, { ...ALL, query: 'heise' }).map((row) => row.host)).toEqual(['heise.de']);
  });

  it('counts per country ignoring the country filter', () => {
    expect(countByCountry(rows, { ...ALL, country: 'AT' })).toEqual(new Map([['DE', 3]]));
  });

  it('filters by country', () => {
    expect(filterRows(rows, { ...ALL, country: 'AT' })).toEqual([]);
  });

  it('marks one more feed subscribed', () => {
    const next = withSubscribed(rows, 'https://www.zeit.de/politik/index');
    expect(next[0].feeds[0].subscribed).toBe(true);
    expect(next[0].following).toBe(true);
  });
});
```

- [ ] **Step 2: Run to see it fail**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory/feed-directory-rows.spec.ts`
Expected: FAIL — module not found.

- [ ] **Step 3: Implement**

```ts
import { FeedDirectoryCountryFile, FeedDirectoryDomainTuple, FeedDirectorySubscribed } from './feed-directory.models';
import { feedDirectoryHost, feedDirectoryUrl } from './feed-directory-url';

// (types from the Interfaces block)

const LONG_READ_CHARACTERS = 2500;
const SUMMARY_CHARACTERS = 500;

export function lengthOf(averageLength: number | null): FeedDirectoryLength | null {
  if (averageLength === null) return null;
  if (averageLength >= LONG_READ_CHARACTERS) return 'long';
  return averageLength >= SUMMARY_CHARACTERS ? 'summaries' : 'headlines';
}

export function prepareRows(
  file: FeedDirectoryCountryFile,
  scopeLanguages: ReadonlySet<string>,
  subscribed: FeedDirectorySubscribed,
): FeedDirectoryRow[] {
  const subscribedUrls = new Set(subscribed.urls);
  return file.domains
    .map((domain) => toRow(file, domain, subscribedUrls, subscribed.hosts))
    .filter((row) => row.languages.some((language) => scopeLanguages.has(language)));
}

function toRow(
  file: FeedDirectoryCountryFile,
  [host, rank, averageLength, languageIndexes, groups, summary]: FeedDirectoryDomainTuple,
  subscribedUrls: ReadonlySet<string>,
  followedHosts: readonly string[],
): FeedDirectoryRow {
  const feeds = groups.flatMap(([categoryIndex, podcast, urls]) =>
    urls.map((url) => ({
      url,
      category: file.categories[categoryIndex],
      podcast: podcast === 1,
      subscribed: subscribedUrls.has(feedDirectoryUrl(url)),
    })),
  );
  return {
    host,
    country: file.country,
    rank,
    length: lengthOf(averageLength),
    languages: languageIndexes.map((index) => file.languages[index]),
    summary,
    feeds,
    following: isFollowed(host, followedHosts),
    searchText: `${host}\n${summary ?? ''}`.toLowerCase(),
  };
}

function isFollowed(host: string, followedHosts: readonly string[]): boolean {
  const domain = feedDirectoryHost(`https://${host}`);
  return followedHosts.some((followed) => followed === domain || followed.endsWith(`.${domain}`));
}

export function feedsIn(row: FeedDirectoryRow, mode: FeedDirectoryMode): FeedDirectoryFeed[] {
  return row.feeds.filter((feed) => feed.podcast === (mode === 'podcasts'));
}

function matches(row: FeedDirectoryRow, filter: FeedDirectoryFilter): boolean {
  const feeds = feedsIn(row, filter.mode);
  if (feeds.length === 0) return false;
  if (filter.categories.size && !feeds.some((feed) => filter.categories.has(feed.category))) return false;
  if (filter.lengths.size && (row.length === null || !filter.lengths.has(row.length))) return false;
  if (filter.languages.size && !row.languages.some((language) => filter.languages.has(language))) return false;
  return !filter.query || row.searchText.includes(filter.query.toLowerCase());
}

const byTop = (left: FeedDirectoryRow, right: FeedDirectoryRow): number =>
  (left.rank ?? Infinity) - (right.rank ?? Infinity) || left.host.localeCompare(right.host);

function comparator(filter: FeedDirectoryFilter): (left: FeedDirectoryRow, right: FeedDirectoryRow) => number {
  switch (filter.sort) {
    case 'az':
      return (left, right) => left.host.localeCompare(right.host);
    case 'feeds':
      return (left, right) =>
        feedsIn(right, filter.mode).length - feedsIn(left, filter.mode).length || byTop(left, right);
    default:
      return byTop;
  }
}

export function filterRows(rows: readonly FeedDirectoryRow[], filter: FeedDirectoryFilter): FeedDirectoryRow[] {
  return rows
    .filter((row) => (filter.country === null || row.country === filter.country) && matches(row, filter))
    .sort(comparator(filter));
}

export function countByCountry(rows: readonly FeedDirectoryRow[], filter: FeedDirectoryFilter): Map<string, number> {
  const counts = new Map<string, number>();
  for (const row of rows) {
    if (matches(row, filter)) counts.set(row.country, (counts.get(row.country) ?? 0) + 1);
  }
  return counts;
}

export function withSubscribed(rows: readonly FeedDirectoryRow[], feedUrl: string): FeedDirectoryRow[] {
  return rows.map((row) =>
    row.feeds.some((feed) => feed.url === feedUrl)
      ? {
          ...row,
          following: true,
          feeds: row.feeds.map((feed) => (feed.url === feedUrl ? { ...feed, subscribed: true } : feed)),
        }
      : row,
  );
}
```

`a - b || …` with `Infinity - Infinity` is `NaN`, which is falsy, so two unranked rows fall through to the host comparison — the test "unranked last, then host" covers it.

- [ ] **Step 4: Run the tests**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory/feed-directory-rows.spec.ts`
Expected: PASS (16 tests).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/feed-directory/feed-directory-rows.ts frontend/src/app/settings/feed-directory/feed-directory-rows.spec.ts
git commit -m "feat(#2176): feed directory rows prepared, filtered and sorted"
```

---

### Task 8: Store and scope service

**Files:**
- Create: `frontend/src/app/settings/feed-directory/feed-directory-scope.service.ts` (+ `.spec.ts`)
- Create: `frontend/src/app/settings/feed-directory/feed-directory.store.ts` (+ `.spec.ts`)

**Interfaces:**
- Consumes: `AuthService.user` / `ensureUserLoaded()`, `FeedDirectoryApi`, Task 7 functions.
- Produces: `FeedDirectoryScopeService` (root) with `scope: Signal<FeedDirectoryScope>` (empty lists when unset), `hasScope: Signal<boolean>`, `save(scope): Observable<boolean>` — on success it sets `AuthService.user` to the returned profile, so `scope` updates everywhere.
- Produces: `FeedDirectoryStore` (provided by the section component, not root) with signals `status: 'loading' | 'ready' | 'failed'`, `index`, `mode`, `country`, `categories`, `lengths`, `languages`, `query`, `sort`, computed `rows` (filtered), `total` (rows in scope and mode), `countryCounts`, `categoryOptions` (categories present in the current mode), and methods `load()`, `retry()`, `markSubscribed(feedUrl: string)`, `toggleCategory(name)`, `toggleLength(length)`, `toggleLanguage(code)`.

- [ ] **Step 1: Failing tests**

Scope service: with `AuthService.user` set to a profile whose `preferences.feedDirectoryScope` is `{countries:['DE'],languages:['de']}`, `scope()` returns it and `hasScope()` is true; with the field missing, `hasScope()` is false; `save()` PATCHes and on a `200` sets the user (assert `auth.user()?.preferences.feedDirectoryScope`), on an error resolves `false` and leaves the user unchanged.

Store: with `FeedDirectoryApi` replaced by a stub returning the `DE` fixture from Task 7 (and an `AT` file), `index`, and `SUBSCRIBED`:
- `load()` requests one country file per scoped country, in parallel (`forkJoin`), and `status()` becomes `'ready'`;
- `rows()` follows every filter signal (one test per signal, checking hosts);
- `countryCounts()` equals `countByCountry` of the loaded rows;
- a failing country request sets `status()` to `'failed'`, and `retry()` loads again;
- `markSubscribed(url)` flips that feed and the row's `following`.

- [ ] **Step 2: Run to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory`
Expected: FAIL.

- [ ] **Step 3: Implement**

Scope service:

```ts
const EMPTY_SCOPE: FeedDirectoryScope = { countries: [], languages: [] };

@Injectable({ providedIn: 'root' })
export class FeedDirectoryScopeService {
  private readonly auth = inject(AuthService);
  private readonly api = inject(FeedDirectoryApi);

  readonly scope = computed(() => this.auth.user()?.preferences.feedDirectoryScope ?? EMPTY_SCOPE);
  readonly hasScope = computed(() => this.scope().countries.length > 0 && this.scope().languages.length > 0);

  save(scope: FeedDirectoryScope): Observable<boolean> {
    return this.api.saveScope(scope).pipe(
      tap((user) => this.auth.user.set(user)),
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
```

Store:

```ts
@Injectable()
export class FeedDirectoryStore {
  private readonly api = inject(FeedDirectoryApi);
  private readonly scopes = inject(FeedDirectoryScopeService);

  readonly status = signal<'loading' | 'ready' | 'failed'>('loading');
  readonly index = signal<FeedDirectoryIndex | null>(null);
  private readonly loaded = signal<FeedDirectoryRow[]>([]);

  readonly mode = signal<FeedDirectoryMode>('channels');
  readonly country = signal<string | null>(null);
  readonly categories = signal<ReadonlySet<string>>(new Set());
  readonly lengths = signal<ReadonlySet<FeedDirectoryLength>>(new Set());
  readonly languages = signal<ReadonlySet<string>>(new Set());
  readonly query = signal('');
  readonly sort = signal<FeedDirectorySort>('top');

  private readonly filter = computed<FeedDirectoryFilter>(() => ({
    mode: this.mode(),
    country: this.country(),
    categories: this.categories(),
    lengths: this.lengths(),
    languages: this.languages(),
    query: this.query(),
    sort: this.sort(),
  }));

  readonly rows = computed(() => filterRows(this.loaded(), this.filter()));
  readonly countryCounts = computed(() => countByCountry(this.loaded(), { ...this.filter(), country: null }));
  readonly total = computed(() => this.loaded().filter((row) => feedsIn(row, this.mode()).length > 0).length);
  readonly categoryOptions = computed(() =>
    [...new Set(this.loaded().flatMap((row) => feedsIn(row, this.mode()).map((feed) => feed.category)))].sort(),
  );

  load(): void {
    const scope = this.scopes.scope();
    const scopeLanguages = new Set(scope.languages);
    this.status.set('loading');
    forkJoin({
      index: this.api.index(),
      subscribed: this.api.subscribed(),
      files: forkJoin(scope.countries.map((code) => this.api.country(code))),
    }).subscribe({
      next: ({ index, subscribed, files }) => {
        this.index.set(index);
        this.loaded.set(files.flatMap((file) => prepareRows(file, scopeLanguages, subscribed)));
        this.status.set('ready');
      },
      error: () => this.status.set('failed'),
    });
  }

  retry(): void {
    this.load();
  }

  markSubscribed(feedUrl: string): void {
    this.loaded.update((rows) => withSubscribed(rows, feedUrl));
  }

  toggleCategory(name: string): void {
    this.categories.update((set) => toggled(set, name));
  }

  toggleLength(length: FeedDirectoryLength): void {
    this.lengths.update((set) => toggled(set, length));
  }

  toggleLanguage(code: string): void {
    this.languages.update((set) => toggled(set, code));
  }
}

function toggled<T>(set: ReadonlySet<T>, value: T): ReadonlySet<T> {
  const next = new Set(set);
  if (next.has(value)) {
    next.delete(value);
  } else {
    next.add(value);
  }
  return next;
}
```

`countryCounts` passes `country: null` because `countByCountry` ignores the country by design; keep that explicit for the reader.

- [ ] **Step 4: Run the tests**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings/feed-directory
git commit -m "feat(#2176): feed directory store and scope service"
```

---

### Task 9: Scope picker and its dialog

**Files:**
- Create: `frontend/src/app/settings/feed-directory/scope-picker/feed-directory-scope-picker.component.{ts,html,scss,spec.ts}`
- Create: `frontend/src/app/settings/feed-directory/scope-dialog/feed-directory-scope-dialog.component.{ts,html,scss}`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `FeedDirectoryApi.index()`, `FeedDirectoryScopeService`.
- Produces: `<app-feed-directory-scope-picker [initial]="scope" (saved)="…" />` — `initial: FeedDirectoryScope`, `saved: output<FeedDirectoryScope>()` emitted after a successful `save()`. `FeedDirectoryScopeDialogComponent` opened with `Dialog.open(FeedDirectoryScopeDialogComponent, { panelClass: 'app-dialog' })`, closing with `true` after a save.

Match the picker block of the mockup: two boxes side by side (stacked under `bp.$bp-md`), each with a heading, the picks as removable accent chips, a filter `<app-search-field>`, and a checklist with right-aligned counts. Country names: `new Intl.DisplayNames([transloco.getActiveLang()], { type: 'region' }).of(code) ?? indexName`; language names: the same with `type: 'language'`. Sort each list by display name. The Save button (`<app-button variant="primary">`) is disabled until both lists have a pick (the one place a disabled button is right: the server would refuse); a failed save shows `<app-error-banner>`.

- [ ] **Step 1: Failing component test**

With the index stub (AT, DE; de, en) and `FeedDirectoryScopeService.save` spied to return `of(true)`:
- renders a checkbox per country and language with its count;
- Save is disabled until one country and one language are checked;
- typing in the country filter narrows the list;
- clicking Save calls `save({ countries: ['DE'], languages: ['de'] })` and emits `saved`;
- `initial` pre-checks its values and shows them as chips; removing a chip unchecks it.

Use `provideTranslocoTesting()` from `frontend/src/testing/transloco-testing.ts`.

- [ ] **Step 2: Run to see it fail, implement, run to see it pass**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory/scope-picker`

i18n keys (en; add German equivalents to `de.json`):

```json
"feedDirectory": {
  "title": "Feed directory",
  "summary": "{{count}} sites in your countries and languages",
  "changeScope": "Change scope",
  "scope": {
    "heading": "Choose your feed directory",
    "intro": "Pick the countries and languages you read. The directory only shows those.",
    "countries": "Countries",
    "languages": "Languages",
    "filterCountries": "Filter countries",
    "filterLanguages": "Filter languages",
    "remove": "Remove {{name}}",
    "save": "Save",
    "saveFailed": "Your choice wasn't saved. Try again."
  }
}
```

Put the block under the existing `settings` object (so keys read `settings.feedDirectory.…`); the section label key for the nav is `settings.feedDirectory.title`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/settings/feed-directory frontend/public/i18n
git commit -m "feat(#2176): feed directory scope picker"
```

---

### Task 10: The Settings section — head, toolbar, rows, footer

**Files:**
- Create: `frontend/src/app/settings/feed-directory/feed-directory-section.component.{ts,html,scss,spec.ts}`
- Create: `frontend/src/app/settings/feed-directory/row/feed-directory-row.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/settings/settings-sections.ts`, `frontend/src/app/settings/settings.routes.ts`, `frontend/public/i18n/{en,de}.json`

**Interfaces:**
- Consumes: `FeedDirectoryStore`, `FeedDirectoryScopeService`, picker and dialog from Task 9, `feedsIn`.
- Produces: route `/settings/feed-directory`; `<app-feed-directory-row [row] [mode] (subscribe)="…" />` where `subscribe` emits the row's host-level click target: `output<{ row: FeedDirectoryRow; anchor: HTMLElement }>()`.

- [ ] **Step 1: Register the section**

`settings-sections.ts`, directly after the `organise` entry:

```ts
  {
    path: 'feed-directory',
    icon: 'travel_explore',
    labelKey: 'settings.feedDirectory.title',
    group: 'general',
    wide: true,
  },
```

`settings.routes.ts`, after the `organise` route:

```ts
      {
        path: 'feed-directory',
        title: sectionLabelKey('feed-directory'),
        loadComponent: () =>
          import('./feed-directory/feed-directory-section.component').then(
            (module) => module.FeedDirectorySectionComponent,
          ),
      },
```

Update `settings-sections.spec.ts` / `settings.routes.spec.ts` if they pin the section list.

- [ ] **Step 2: Failing section test**

- with no scope: the picker renders inline and no country file is requested;
- with a scope: the store loads, the head shows "N sites…", rows render, the scope chips show the scoped countries and languages;
- the Countries pill is absent for a one-country scope and present for two; the Language pill likewise;
- typing in search narrows the rows;
- the Channels/Podcasts control switches `store.mode`;
- `status() === 'failed'` shows the error with a Retry button calling `store.retry()`;
- Change scope opens the dialog (spy on `Dialog.open`).

- [ ] **Step 3: Implement**

Template outline (match mockup variant C; reuse Organise's `.tools`, `.menu`/`.pop` + `appDismissOnOutside` pattern for the filter pills, and its `<app-settings-stack>` / `<app-settings-group>` shell):

```html
<app-settings-stack>
  @if (!scopes.hasScope()) {
    <app-settings-group icon="travel_explore" [title]="'settings.feedDirectory.scope.heading' | transloco"
      [caption]="'settings.feedDirectory.scope.intro' | transloco">
      <app-feed-directory-scope-picker [initial]="scopes.scope()" (saved)="store.load()" />
    </app-settings-group>
  } @else {
    <app-settings-group icon="travel_explore" [title]="'settings.feedDirectory.title' | transloco"
      [caption]="'settings.feedDirectory.summary' | transloco: { count: store.total() }">
      <app-button groupActions size="sm" data-test="change-scope" (click)="changeScope()">
        <app-icon name="tune" size="sm" /> {{ 'settings.feedDirectory.changeScope' | transloco }}
      </app-button>
      <div class="tools"><!-- segmented, pills, sort, search --></div>
      <div class="scope-line"><!-- globe icon, chips, "N shown" --></div>
    </app-settings-group>

    @switch (store.status()) {
      @case ('loading') { <app-skeleton … /> }
      @case ('failed') { <app-error-banner …>…retry…</app-error-banner> }
      @default {
        <section class="rows-card">
          @if (store.rows().length === 0) {
            <p class="empty">{{ 'settings.feedDirectory.noMatches' | transloco }}</p>
          } @else {
            <cdk-virtual-scroll-viewport [itemSize]="rowHeight" class="viewport">
              <app-feed-directory-row *cdkVirtualFor="let row of store.rows(); trackBy: byHost"
                [row]="row" [mode]="store.mode()" (subscribe)="subscriber.open($event)" />
            </cdk-virtual-scroll-viewport>
          }
        </section>
      }
    }
    <p class="attribution"><!-- ODbL line with links --></p>
  }
</app-settings-stack>
```

`rowHeight` is one constant shared with the row's SCSS height (expose it as a CSS custom property set from the component, `[style.--row-h.px]`, so SCSS and the viewport cannot drift). The viewport needs a definite height: size it to the Settings scroll area (`height: calc(100dvh - …)` built from existing tokens) — check how the Settings shell scrolls before choosing; the row card must not create a second scrollbar on a phone if the shell already scrolls the page — in that case use `cdkVirtualScrollingElement` on the shell's scroll container (`grep -rn cdkVirtualScrollingElement frontend/src/app`).

Row template: host + Following badge (`<span class="follow"><app-icon name="check" size="text" />…`), meta line (country display name · language display names · length label · rank), summary clamped to two lines, category chips (hidden under `bp.$bp-sm`), and the action button: one feed in the mode → `+ Subscribe` or `✓ Subscribed`; several → `N feeds ▾` or `N feeds · M subscribed ▾`. i18n keys: `settings.feedDirectory.{subscribe,subscribed,following,feeds,feedsSubscribed,rank,lengths.headlines,lengths.summaries,lengths.long,mode.channels,mode.podcasts,sort.top,sort.az,sort.feeds,filters.countries,filters.allCountries,filters.category,filters.length,filters.language,search,shown,noMatches,loadFailed,retry,attribution}`.

- [ ] **Step 4: Run tests, lint**

Run: `docker compose exec -T frontend npx jest src/app/settings` then `docker compose exec -T frontend npm run check`
Expected: PASS; no Stylelint hex/px violations.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/settings frontend/public/i18n
git commit -m "feat(#2176): feed directory settings section"
```

---

### Task 11: Subscribing — dialog, popover, action sheet, entry link

**Files:**
- Create: `frontend/src/app/settings/feed-directory/feed-directory-subscriber.ts` (+ `.spec.ts`)
- Create: `frontend/src/app/settings/feed-directory/feeds-popover/feed-directory-feeds-popover.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/src/app/reader/feeds/add-feed/add-feed-dialog.component.{html,ts}`, `frontend/public/i18n/{en,de}.json`

**Interfaces:**
- Consumes: `Dialog` (`@angular/cdk/dialog`), `AddFeedDialogComponent` + `AddFeedDialogData`, `ActionSheet`, `Overlay`, `SubscriptionsStore.reloadQuietly()` (as `ManageActionsService.addFeed()` does), `FeedDirectoryStore.markSubscribed()`, `feedsIn`.
- Produces: `FeedDirectorySubscriber` (provided by the section) with `open({ row, anchor })`.

Behaviour:
- one feed in the mode → `subscribeTo(url)`;
- several, fine pointer (`matchMedia('(pointer: fine)')`) → a CDK overlay positioned under `anchor` (flexible position strategy, end-aligned, flips above when there is no room), holding `<app-feed-directory-feeds-popover>`; choosing a feed closes it and calls `subscribeTo(url)`; Escape and outside click close it;
- several, coarse pointer → `ActionSheet.open({ title: row.host, actions })` where `actions` is, per category, `{ note: category }` followed by one `{ id: url, label: path, symbol: subscribed ? 'check' : 'add', disabled: subscribed }`; the chosen id is the URL to `subscribeTo`.
- `subscribeTo(url)`: `dialog.open<SubscriptionDto>(AddFeedDialogComponent, { panelClass: 'app-dialog', data: { url } satisfies AddFeedDialogData })`; on a closed value, `store.markSubscribed(url)` and `subscriptions.reloadQuietly()`.

`path` is the URL without scheme and host (`/kultur/index.rss`), the full URL as `title` tooltip on desktop.

Add-feed dialog footer, after the existing `/discover` link:

```html
    <a footer class="browse" routerLink="/settings/feed-directory" (click)="ref.close()">
      {{ 'settings.feedDirectory.browse' | transloco }}
    </a>
```

(`"browse": "Browse feed directory"` in en, German in de.)

- [ ] **Step 1: Failing tests** — subscriber: single feed opens the dialog with `{ url }`; a closed subscription marks the feed and reloads subscriptions; a dismissed dialog changes nothing; coarse pointer opens the action sheet with notes per category and disabled subscribed feeds; a sheet choice opens the dialog for that URL. Popover: lists feeds grouped by category with path, Subscribed mark, emits the chosen URL.
- [ ] **Step 2: Run to see them fail; implement; run to see them pass**

Run: `docker compose exec -T frontend npx jest src/app/settings/feed-directory src/app/reader/feeds/add-feed`

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app frontend/public/i18n
git commit -m "feat(#2176): subscribe from a feed directory row"
```

---

### Task 12: Playwright smoke

**Files:**
- Create: `frontend/e2e/feed-directory.spec.ts`

Follow `frontend/e2e/magazine-kicker-one-line.spec.ts` for signing in and stubbing. Stub `**/api/feed-directory/index`, `**/api/feed-directory/countries/DE` (plain JSON body is fine — the browser only decodes gzip when the header says so), `**/api/feed-directory/subscribed`, and `**/api/me/feed-directory-scope` (respond with the current `/api/me` payload plus the scope). Flow: open `/settings/feed-directory` → the picker shows → check Germany and German → Save → rows show → type a host into search → one row → click its Subscribe → the add-feed dialog opens with the URL in its field. Leave the account's real scope untouched: the PATCH is stubbed, so nothing persists.

Run: `cd frontend && npm run e2e -- feed-directory.spec.ts` (Docker stack up, from the checkout that runs it).
Expected: PASS.

```bash
git add frontend/e2e/feed-directory.spec.ts
git commit -m "test(#2176): feed directory smoke"
```

---

### Task 13: Gates and live check

- [ ] `cd backend && composer check && composer md` — clean on every touched `src` file.
- [ ] `cd backend && php bin/phpunit` and, at the same time, `docker compose exec php composer test:parallel` — both green.
- [ ] `cd backend && composer infection:diff` — at or above `minMsi`.
- [ ] `docker compose exec -T frontend npm run check` — green.
- [ ] PhpStorm inspections (`mcp__phpstorm__lint_files`) on changed PHP — no ERROR/WARNING.
- [ ] Live check on `http://localhost:4200/settings/feed-directory` against mockup variant C, desktop and phone width, dark and light: pick a scope, filter, open a multi-feed popover, subscribe one feed, see Following/Subscribed update.
- [ ] Scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`.
- [ ] `/simplify` over the branch, then open the PR into `develop` with `Closes #2176`.
