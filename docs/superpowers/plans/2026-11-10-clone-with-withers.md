# Withers on clone-with Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A wither that changes some fields of a readonly model copies it with PHP 8.5's `clone($this, [...])` instead of restating every constructor argument (#2150).

**Architecture:** Mechanical rewrite of `new self(...)` / `new static(...)` inside instance methods, driven by nikic/php-parser so each changed argument maps to its promoted property by name. Only classes whose constructor does nothing but promote are converted (`clone` skips the constructor).

**Tech Stack:** PHP 8.5 clone-with, nikic/php-parser (dev dependency, already in vendor), PHPUnit 12.

**Spec:** issue #2150.

## Global Constraints

- Eligible: every constructor parameter is promoted and the constructor body is empty.
- Eligible copy-construction: passes every constructor argument, at least two of them as `$this-><same name>`.
- Not converted: `EntryQuery`, `ParsedEntryModel`, `EntrySearchResultModel` (their constructors validate or derive).
- `FetchAttemptModel`'s withers lean on constructor defaults to reset `pinnedAddressAttempt`, `proxy` and `firstSend`; converting them means naming those resets in the clone array.
- Behaviour unchanged: the existing model tests cover each wither.

---

### Task 1: Rewrite the eligible copy-constructions

**Files:** the classes the scan lists with `eligible=yes` (`DueFeedCriteria`, `EntryListRow`, `RecommendationFeedRow`, `MediaCandidateModel`, `RecommendationRunReportModel`, `CommentViewerModel`, `CommentPostModel`, `EntryCommentModel`, `IncomingFeedModel`, `RefreshRequestModel`, `ParsedFeedModel`, `ImageIdentityModel`, `DeclaredImageModel`, `TimelinePageModel`, `ImportCandidateModel`, `PrivateFeedImportModel`, `HiddenProfileSwitchReportModel`, `SocialPostModel`, `ApiRequestModel`, `TrendingStoryCardModel`) plus `FetchAttemptModel` by hand.

- [ ] **Step 1:** Run the scanner (scratchpad `clone/scan.php src`) and keep its list as the worklist.
- [ ] **Step 2:** Rewrite each listed `new self(...)` to

```php
return clone($this, ['mimeType' => $mimeType]);
```

  one key per changed argument, keyed by the promoted property name, the argument's source text as the value; several keys go one per line.
- [ ] **Step 3:** `FetchAttemptModel`:

```php
public function followedTo(string $url, bool $permanent): self
{
    return clone($this, [
        'url' => $url,
        'permanentRedirect' => $this->permanentRedirect || $permanent,
        'hop' => $this->hop + 1,
        // A redirect lands on a fresh host, so the address pins start over.
        'pinnedAddressAttempt' => 0,
        'firstSend' => false,
    ]);
}

public function overNextPinnedAddress(): self
{
    return clone($this, ['pinnedAddressAttempt' => $this->pinnedAddressAttempt + 1, 'firstSend' => false]);
}

public function withoutProxy(): self
{
    return clone($this, ['pinnedAddressAttempt' => 0, 'proxy' => null, 'firstSend' => false]);
}
```

- [ ] **Step 4:** Re-run the scanner: only the three excluded classes remain.
- [ ] **Step 5:** `php -l` each file, the tests of every touched model, the full suite, `composer check`, `composer md`.
- [ ] **Step 6:** Commit `refactor(#2150): withers copy with clone-with`.
