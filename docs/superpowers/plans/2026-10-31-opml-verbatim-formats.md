# OPML Keeps Verbatim Formats Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An OPML export and re-import keeps a feed's published verbatim source format (`wp-json`, `mixcloud-api`) instead of turning it into an XML feed that cannot parse, and scraped feeds never enter or leave an OPML file (#1934).

**Architecture:** The exporter writes a `sourceFormat` attribute on a feed outline whose format is verbatim; the importer reads it back into the `BulkSubscribeItemModel::$sourceFormat` the bulk subscriber already honours. Without the attribute, a Mixcloud cloudcasts API URL imports as `mixcloud-api`. A scraped feed is this reader's own reading of a page: the export leaves it out (`SourceFormat::NOT_IN_OPML`) and a `scraped` outline is dropped before the bulk subscribe and counted as invalid, whatever the account's scrape preference.

**Changed after this plan was written:** the owner decided on 2026-10-31 that scraped feeds never travel in OPML. Task 3 originally gated scraped outlines on the scrape preference; that gate was removed in `fix(#1934): keep scraped feeds out of OPML in both directions`. Task 3 below records what was finally built.

**Tech Stack:** Symfony 7.4, PHP 8.4, PHPUnit 12.

**Spec:** none written; the design was approved in chat on 2026-10-31 and is recorded in issue #1934 and in this header. Rulings are provisional against it.

## Global Constraints

- Branch `feature/1934-opml-verbatim-formats`, cut from `feature/1932-mixcloud-profiles`; commits `type(#1934): …`, no Claude attribution.
- Only `SourceFormat::VERBATIM` values are written or read; any other declared value imports as `SourceFormat::XML` (a social format must never come back from a file).
- The outline keeps `type="rss"`; the attribute name is exactly `sourceFormat`.
- A scraped outline counts in `OpmlImportResultModel::$invalid` and persists no `Feed` row, whatever the scrape preference; the export never writes a scraped feed.
- A declared `sourceFormat` (trimmed) wins over the cloudcasts-URL inference.
- Gates: `composer check`, `composer md`, both PHPUnit legs, `composer infection:diff`, PhpStorm lint on changed PHP (block on ERROR/WARNING). CLAUDE.md Clean Code rules apply; no new comments unless a reader would get the code wrong without one.

---

### Task 1: Export the verbatim format

**Files:**
- Modify: `backend/src/Service/Opml/OpmlExporter.php` (`feedOutline`)
- Test: `backend/tests/Service/Opml/OpmlExporterTest.php`

**Interfaces:**
- Consumes: `ExportedSubscriptionModel::$feedSourceFormat` (already the format to export: a Bluesky author feed's is `XML`), `SourceFormat::isVerbatim(?string)`.
- Produces: outline attribute `sourceFormat="<format>"` on verbatim feeds only.

- [ ] **Step 1: Write the failing tests** — add to `OpmlExporterTest`:

```php
    public function testAVerbatimFeedCarriesItsSourceFormat(): void
    {
        $user = new User('verbatim@example.com', new \DateTimeImmutable('2026-10-31T00:00:00Z'));
        $this->entityManager->persist($user);
        $feed = new Feed('https://api.mixcloud.com/johndigweed/cloudcasts/');
        $feed->setSourceFormat(SourceFormat::MIXCLOUD_API);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-10-31T00:00:00Z')));
        $this->entityManager->flush();

        $outline = $this->onlyFeedOutline($user);

        self::assertSame(SourceFormat::MIXCLOUD_API, $outline->getAttribute('sourceFormat'));
        self::assertSame('rss', $outline->getAttribute('type'));
    }

    public function testAnXmlFeedCarriesNoSourceFormat(): void
    {
        $user = new User('plain@example.com', new \DateTimeImmutable('2026-10-31T00:00:00Z'));
        $this->entityManager->persist($user);
        $feed = new Feed('https://plain.example.com/feed.xml');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-10-31T00:00:00Z')));
        $this->entityManager->flush();

        $outline = $this->onlyFeedOutline($user);

        self::assertFalse($outline->hasAttribute('sourceFormat'));
    }
```

and the helper beside `exportedDocument` (`DOMXPath::query` returns `false` on a bad query, so no `?->`):

```php
    private function onlyFeedOutline(User $user): \DOMElement
    {
        $outlines = (new \DOMXPath($this->exportedDocument($user)))->query('//outline[@xmlUrl]');
        self::assertNotFalse($outlines);
        self::assertCount(1, $outlines);
        $outline = $outlines->item(0);
        self::assertInstanceOf(\DOMElement::class, $outline);

        return $outline;
    }
```

- [ ] **Step 2: Run to see the first fail** — `php bin/phpunit --filter 'OpmlExporterTest'` → `testAVerbatimFeedCarriesItsSourceFormat` fails (`''` vs `'mixcloud-api'`); the XML test passes already.

- [ ] **Step 3: Implement** — in `feedOutline`, right after the `xmlUrl` line:

```php
        if (SourceFormat::isVerbatim($exported->feedSourceFormat)) {
            $outline->setAttribute('sourceFormat', $exported->feedSourceFormat);
        }
```

and `use App\Enum\SourceFormat;`.

- [ ] **Step 4: Run** — `php bin/phpunit --filter 'OpmlExporterTest'` → all pass.

- [ ] **Step 5: Commit** — `feat(#1934): export a verbatim feed's source format in OPML`.

### Task 2: Import the verbatim format, and infer Mixcloud

**Files:**
- Modify: `backend/src/Service/Url/Support/MixcloudUrl.php` (add `isCloudcastsUrl`)
- Modify: `backend/src/Service/Opml/OpmlImporter.php` (`collectFeeds`, new private `sourceFormatOf`)
- Test: `backend/tests/Service/Url/Support/MixcloudUrlTest.php`, `backend/tests/Service/Opml/OpmlImporterTest.php`

**Interfaces:**
- Produces: `MixcloudUrl::isCloudcastsUrl(string $url): bool` — true for host `api.mixcloud.com` (any case) and path `/<SEGMENT>/cloudcasts/` with the trailing slash optional; a query is ignored. Follow-up B (#1932 discovery gaps) will reuse it.

- [ ] **Step 1: Write the failing URL test** — add to `MixcloudUrlTest`:

```php
    /** @return iterable<string, array{string, bool}> */
    public static function cloudcastsUrls(): iterable
    {
        yield 'a cloudcasts list' => ['https://api.mixcloud.com/johndigweed/cloudcasts/', true];
        yield 'without the trailing slash' => ['https://api.mixcloud.com/johndigweed/cloudcasts', true];
        yield 'with a query' => ['https://api.mixcloud.com/johndigweed/cloudcasts/?limit=20', true];
        yield 'a mixed-case host' => ['https://API.Mixcloud.com/johndigweed/cloudcasts/', true];
        yield 'an encoded user' => ['https://api.mixcloud.com/caf%C3%A9/cloudcasts/', true];
        yield 'the user itself' => ['https://api.mixcloud.com/johndigweed/', false];
        yield 'another list' => ['https://api.mixcloud.com/johndigweed/favorites/', false];
        yield 'a show' => ['https://api.mixcloud.com/johndigweed/transitions-1154/', false];
        yield 'a deeper path' => ['https://api.mixcloud.com/johndigweed/cloudcasts/extra/', false];
        yield 'the site host' => ['https://www.mixcloud.com/johndigweed/cloudcasts/', false];
        yield 'a look-alike host' => ['https://api.mixcloud.com.evil.test/johndigweed/cloudcasts/', false];
    }

    #[DataProvider('cloudcastsUrls')]
    public function testTellsACloudcastsListFromAnythingElse(string $url, bool $isCloudcasts): void
    {
        self::assertSame($isCloudcasts, MixcloudUrl::isCloudcastsUrl($url));
    }
```

- [ ] **Step 2: Write the failing import tests** — add to `OpmlImporterTest` (`use App\Enum\SourceFormat;`):

```php
    public function testADeclaredVerbatimFormatImportsAsThatFormat(): void
    {
        $user = $this->user('wpjson@example.com');
        $url = 'https://blog.example.com/wp-json/wp/v2/posts';

        $this->importer()->import(
            $user,
            $this->opml('<outline type="rss" text="Blog" xmlUrl="' . $url . '" sourceFormat="wp-json"/>'),
        );

        self::assertSame(SourceFormat::WP_JSON, $this->feedsWithUrl($url)[0]->getSourceFormat());
    }

    public function testADeclaredFormatThatIsNotVerbatimImportsAsXml(): void
    {
        $user = $this->user('social@example.com');
        $url = 'https://social.example.com/feed';

        $this->importer()->import(
            $user,
            $this->opml('<outline type="rss" text="S" xmlUrl="' . $url . '" sourceFormat="bluesky-api"/>'),
        );

        self::assertSame(SourceFormat::XML, $this->feedsWithUrl($url)[0]->getSourceFormat());
    }

    public function testACloudcastsUrlWithoutAFormatImportsAsMixcloud(): void
    {
        $user = $this->user('mixcloud@example.com');
        $url = 'https://api.mixcloud.com/johndigweed/cloudcasts/';

        $this->importer()->import($user, $this->opml('<outline type="rss" text="JD" xmlUrl="' . $url . '"/>'));

        self::assertSame(SourceFormat::MIXCLOUD_API, $this->feedsWithUrl($url)[0]->getSourceFormat());
    }
```

- [ ] **Step 3: Run to see them fail** — `php bin/phpunit --filter 'MixcloudUrlTest|OpmlImporterTest'` → the URL test errors (undefined method); the wp-json and Mixcloud import tests fail (`xml` stored); the not-verbatim test passes already.

- [ ] **Step 4: Implement** — `MixcloudUrl`:

```php
    private const string API_HOST = 'api.mixcloud.com';

    public static function isCloudcastsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || strtolower($parts['host'] ?? '') !== self::API_HOST) {
            return false;
        }

        return preg_match('#^/' . self::SEGMENT . '/cloudcasts/?\z#', $parts['path'] ?? '') === 1;
    }
```

`OpmlImporter::collectFeeds` builds the item with the format:

```php
                $items[] = new BulkSubscribeItemModel(
                    feedUrl: $xmlUrl,
                    tagPath: $groupPath,
                    sourceFormat: $this->sourceFormatOf($child, $xmlUrl),
                );
```

and the new private method (with `use App\Enum\SourceFormat;` and `use App\Service\Url\Support\MixcloudUrl;`):

```php
    private function sourceFormatOf(\DOMElement $outline, string $xmlUrl): string
    {
        $declared = $outline->getAttribute('sourceFormat');
        if (SourceFormat::isVerbatim($declared)) {
            return $declared;
        }

        return MixcloudUrl::isCloudcastsUrl($xmlUrl) ? SourceFormat::MIXCLOUD_API : SourceFormat::XML;
    }
```

- [ ] **Step 5: Run** — `php bin/phpunit --filter 'MixcloudUrlTest|OpmlImporterTest'` → all pass.

- [ ] **Step 6: Commit** — `feat(#1934): import a feed's verbatim source format from OPML`.

### Task 3: Keep scraped feeds out of OPML (as built)

**Files:** `backend/src/Enum/SourceFormat.php` (`NOT_IN_OPML`), `backend/src/Repository/SubscriptionRepository.php` (`findExportableForUserWithTags` excludes it), `backend/src/Service/Opml/OpmlImporter.php` (`withoutScraped`, the invalid count), tests in `backend/tests/Repository/ExportableSubscriptionsTest.php` and `backend/tests/Service/Opml/OpmlImporterTest.php`.

- The export query excludes `SourceFormat::NOT_IN_OPML` (the private and saved formats, plus `scraped`), author feeds aside; a backup still carries scraped feeds.
- The importer drops every item whose format is `scraped` before `BulkSubscriber::subscribeAll` and adds them to `invalid`; it reads no preference.
- Tests: an OPML export leaves a scraped feed out while a backup keeps it; a scraped outline is invalid and persists no row even for an account with scraping on.

### Task 4: Gates

- [ ] `composer check`, `composer md` (every touched `src` file PHPMD-clean), `php bin/phpunit` and `docker compose exec php composer test:parallel` (in parallel), `composer infection:diff` (minMsi from `infection.json5`; kill any escaped mutant with a test), PhpStorm lint on the changed PHP files, scan today's `backend/var/log/dev-*.log`. Fix in a `test(#1934)`/`fix(#1934)` commit.
