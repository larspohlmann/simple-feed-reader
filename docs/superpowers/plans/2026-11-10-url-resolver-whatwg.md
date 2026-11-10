# UrlResolver on the WHATWG parser Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `UrlResolver::resolve()` resolves query-only, fragment, empty and dot-segment references the way browsers do (#2149).

**Architecture:** Relative references go through PHP 8.5's `Uri\WhatWg\Url::parse($reference, $base)`. An absolute http(s) reference still returns unchanged, so stored feed and redirect URLs keep their identity. A base that does not parse or has no host, a reference that does not parse, or a result that is not http(s) throws `FeedUnreachableException`, as today.

**Tech Stack:** PHP 8.5 `Uri\WhatWg\Url`, PHPUnit 12.

**Spec:** issue #2149 (no separate spec; the issue's table is the requirement).

## Global Constraints

- The contract stays: an absolute http(s) URL or `FeedUnreachableException`.
- `SlideImageResolver`'s `//` check (line 122) is a filter for "looks absolute", not resolution: out of scope.
- The other `parse_url()` call sites are out of scope.

---

### Task 1: Resolve with the WHATWG parser

**Files:**
- Modify: `backend/src/Service/Fetch/Support/UrlResolver.php`
- Modify: `backend/src/Service/Reader/Media/Teaser/TeaserPlayerScanner.php:124-127`
- Test: `backend/tests/Service/Fetch/Support/UrlResolverTest.php`

- [ ] **Step 1: Add the failing cases** to `references()`, base `https://example.com/news/2026/article.html?id=7`:

```php
yield 'query-only keeps the path' => [self::ARTICLE, '?page=2', 'https://example.com/news/2026/article.html?page=2'];
yield 'fragment keeps path and query' => [self::ARTICLE, '#comments', 'https://example.com/news/2026/article.html?id=7#comments'];
yield 'dot-dot climbs a directory' => [self::ARTICLE, '../img/a.jpg', 'https://example.com/news/img/a.jpg'];
yield 'dot stays in the directory' => [self::ARTICLE, './b.jpg', 'https://example.com/news/2026/b.jpg'];
yield 'empty is the base itself' => [self::ARTICLE, '', 'https://example.com/news/2026/article.html?id=7'];
```

plus exception cases: `mailto:a@example.org` and `javascript:void(0)` against the article base, and an unparsable base `https://[bad`.

- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Fetch/Support/UrlResolverTest.php` — the five new rows and the non-http rows fail.

- [ ] **Step 3: Implement**

```php
public static function resolve(string $baseUrl, string $location): string
{
    if (AbsoluteHttpUrl::matches($location)) {
        return $location;
    }

    $base = Url::parse($baseUrl);
    $resolved = $base === null || $base->getHost() === null ? null : Url::parse($location, $base);
    if ($resolved === null || !\in_array($resolved->getScheme(), ['http', 'https'], true)) {
        throw new FeedUnreachableException(sprintf('Cannot resolve redirect target "%s"', $location));
    }

    return $resolved->toAsciiString();
}
```

- [ ] **Step 4:** `TeaserPlayerScanner::absolute()` catches `FeedUnreachableException` and returns null, so a `javascript:` headline link drops the link instead of failing the reader.

- [ ] **Step 5: Run** the resolver test, the Fetch, Reader and Discovery test folders, `composer check`, `composer md`.

- [ ] **Step 6: Commit** `fix(#2149): resolve relative references with the whatwg url parser`.
