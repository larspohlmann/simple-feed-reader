# OPML Keeps Verbatim Formats Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An OPML export and re-import keeps a feed's verbatim source format (`scraped`, `wp-json`, `mixcloud-api`) instead of turning it into an XML feed that cannot parse (#1934).

**Architecture:** The exporter writes a `sourceFormat` attribute on a feed outline whose format is verbatim; the importer reads it back into the `BulkSubscribeItemModel::$sourceFormat` the bulk subscriber already honours. Without the attribute, a Mixcloud cloudcasts API URL imports as `mixcloud-api`. A `scraped` outline for an account with scraping off is dropped before the bulk subscribe and counted as invalid, the same gate `SubscriptionService` applies.

**Tech Stack:** Symfony 7.4, PHP 8.4, PHPUnit 12.

**Spec:** none written; the design was approved in chat on 2026-10-31 and is recorded in issue #1934 and in this header. Rulings are provisional against it.

## Global Constraints

- Branch `feature/1934-opml-verbatim-formats`, cut from `feature/1932-mixcloud-profiles`; commits `type(#1934): …`, no Claude attribution.
- Only `SourceFormat::VERBATIM` values are written or read; any other declared value imports as `SourceFormat::XML` (a social format must never come back from a file).
- The outline keeps `type="rss"`; the attribute name is exactly `sourceFormat`.
- A refused scraped outline counts in `OpmlImportResultModel::$invalid` and persists no `Feed` row.
- The scrape decision goes through `App\Service\Discovery\ScrapeFallbackPolicy::forUser()`, never the preference directly.
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

### Task 3: Refuse scraped outlines when scraping is off

**Files:**
- Modify: `backend/src/Service/Opml/OpmlImporter.php` (constructor, `import`, new private `withoutRefusedScrapes`)
- Test: `backend/tests/Service/Opml/OpmlImporterTest.php`

**Interfaces:**
- Consumes: Task 2's items carrying `sourceFormat`; `ScrapeFallbackPolicy::forUser(User): ScrapeFallback` (`App\Service\Discovery\Model\ScrapeFallback::Enabled`).

- [ ] **Step 1: Write the failing tests**:

```php
    public function testAScrapedOutlineIsInvalidWhenScrapingIsOff(): void
    {
        $user = $this->user('noscrape@example.com');
        $scrapedUrl = 'https://page.example.com/news';
        $goodUrl = 'https://ok.example.com/feed.xml';
        $opml = $this->opml(
            '<outline type="rss" text="Page" xmlUrl="' . $scrapedUrl . '" sourceFormat="scraped"/>'
            . '<outline type="rss" text="Good" xmlUrl="' . $goodUrl . '"/>',
        );

        $result = $this->importer()->import($user, $opml);

        self::assertSame(1, $result->invalid);
        self::assertSame(1, $result->imported);
        self::assertSame([], $this->feedsWithUrl($scrapedUrl));
    }

    public function testAScrapedOutlineImportsWhenScrapingIsOn(): void
    {
        $user = $this->user('scrape@example.com');
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $this->entityManager->flush();
        $url = 'https://page.example.com/news';

        $result = $this->importer()->import(
            $user,
            $this->opml('<outline type="rss" text="Page" xmlUrl="' . $url . '" sourceFormat="scraped"/>'),
        );

        self::assertSame(1, $result->imported);
        self::assertSame(SourceFormat::SCRAPED, $this->feedsWithUrl($url)[0]->getSourceFormat());
    }
```

- [ ] **Step 2: Run to see the first fail** — `php bin/phpunit --filter OpmlImporterTest` → the scraping-off test fails (`invalid` 0, `imported` 2).

- [ ] **Step 3: Implement** — inject `private ScrapeFallbackPolicy $scrapeFallbackPolicy` into `OpmlImporter`, then in `import()`:

```php
        $items = $this->collectFeeds($body, []);
        $permitted = $this->withoutRefusedScrapes($user, $items);
        $result = $this->subscriber->subscribeAll($user, $permitted);

        return new OpmlImportResultModel(
            imported: $result->imported,
            alreadySubscribed: $result->alreadySubscribed,
            invalid: $result->invalid + \count($items) - \count($permitted),
            skippedOverLimit: $result->skippedOverLimit,
        );
```

```php
    /**
     * @param list<BulkSubscribeItemModel> $items
     *
     * @return list<BulkSubscribeItemModel>
     */
    private function withoutRefusedScrapes(User $user, array $items): array
    {
        if ($this->scrapeFallbackPolicy->forUser($user) === ScrapeFallback::Enabled) {
            return $items;
        }

        return array_values(array_filter(
            $items,
            static fn (BulkSubscribeItemModel $item): bool => $item->sourceFormat !== SourceFormat::SCRAPED,
        ));
    }
```

- [ ] **Step 4: Run** — `php bin/phpunit --filter OpmlImporterTest` → all pass.

- [ ] **Step 5: Commit** — `feat(#1934): count a scraped OPML outline as invalid while scraping is off`.

### Task 4: Gates

- [ ] `composer check`, `composer md` (every touched `src` file PHPMD-clean), `php bin/phpunit` and `docker compose exec php composer test:parallel` (in parallel), `composer infection:diff` (minMsi from `infection.json5`; kill any escaped mutant with a test), PhpStorm lint on the changed PHP files, scan today's `backend/var/log/dev-*.log`. Fix in a `test(#1934)`/`fix(#1934)` commit.
