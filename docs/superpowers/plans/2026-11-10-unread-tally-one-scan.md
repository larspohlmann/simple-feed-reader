# Unread tally from one scan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `SubscriptionTallyReader::forUser()` scans the user's unread entries once instead of four times (#2159).

**Architecture:** `subscription (user_id, feed_id)` is unique, so every unread entry lies in exactly one of the user's subscriptions. All-items, feed-group and tag scopes restrict by subscription only (`includeInAllItems`, `FeedGroupDql`, the subscription's tags and their ancestors). Each of those counts is therefore the sum of the per-subscription counts over the scope's subscriptions, each subscription once. The entry scan stays `perSubscription()`; the other three take its map and sum over subscription ids from membership queries that touch no entry rows.

**Tech Stack:** Doctrine ORM 3 / DQL, MySQL 8.4 and SQLite, PHPUnit 12.

**Spec:** issue #2159.

## Global Constraints

- Counts unchanged: the existing `UnreadCountRepositoryTest` cases (cross-feed copies, a parent tag counting a grandchild's entry once, a twice-tagged feed counted once in the tags group, feeds hidden from all items) are the equivalence check; their fixtures and expected numbers stay as they are.
- Network counts (`perNetwork`, `perNetworkProfiles`) are out of scope: the issue measured the four, and the network scope joins entries to feeds by entry, not by subscription.
- Queries live in `src/Repository`.

---

### Task 1: Membership queries

**Files:**
- Modify: `backend/src/Repository/SubscriptionScopeRepository.php`
- Test: `backend/tests/Repository/SubscriptionScopeRepositoryTest.php`

**Produces:**
- `idsIncludedInAllItemsForUser(int $userId): list<int>`
- `idsPerTagScopeForUser(int $userId): array<int, list<int>>` — tag id ⇒ the ids of the subscriptions carrying the tag or a tag below it, each once.

- [ ] **Step 1:** Tests: a hidden-from-all-items subscription is absent from the first; a subscription tagged with a child appears under the child and every ancestor, once even when it also carries the parent; another user's tags never appear.
- [ ] **Step 2:** Implement

```php
/** @return list<int> */
public function idsIncludedInAllItemsForUser(int $userId): array
{
    /** @var list<int|string> $ids */
    $ids = $this->createQueryBuilder('s')
        ->select('s.id')
        ->andWhere('s.user = :userId')->setParameter('userId', $userId)
        ->andWhere('s.includeInAllItems = true')
        ->getQuery()
        ->getSingleColumnResult();

    return array_map(intval(...), $ids);
}

/** @return array<int, list<int>> tag id => the subscriptions carrying it or a tag below it, each once */
public function idsPerTagScopeForUser(int $userId): array
{
    $qb = $this->createQueryBuilder('s')
        ->select('DISTINCT scopeTag.id AS tagId', 's.id AS subscriptionId')
        ->innerJoin('s.subscriptionTags', 'st')
        ->innerJoin('st.tag', TagLineageDql::alias('lineage', 0))
        ->innerJoin(Tag::class, 'scopeTag', 'ON', TagLineageDql::reaches('lineage', 'scopeTag.id') . ' AND scopeTag.user = :userId')
        ->andWhere('s.user = :userId')->setParameter('userId', $userId);
    TagLineageDql::joinAncestors($qb, 'lineage');

    $scopes = [];
    foreach ($qb->getQuery()->getScalarResult() as $row) {
        $scopes[(int) $row['tagId']][] = (int) $row['subscriptionId'];
    }

    return $scopes;
}
```

  (Join order adjusted as DQL requires: ancestors before the `scopeTag` join that reads them.)

### Task 2: Counts summed from the per-subscription map

**Files:**
- Modify: `backend/src/Repository/UnreadCountRepository.php`, `backend/src/Service/Subscription/SubscriptionTallyReader.php`
- Test: `backend/tests/Repository/UnreadCountRepositoryTest.php` (call shape only), `backend/tests/Service/Subscription/SubscriptionTallyReaderTest.php`

- [ ] **Step 1:** Change the three signatures to take the map: `allItems(int $userId, array $perSubscription): int`, `perTag(int $userId, array $perSubscription): array`, `perFeedGroup(int $userId, array $perSubscription): array`. Update the tests' calls to pass `$repository->perSubscription($userId)`; run them against the still-old bodies — green (the parameter is unused yet).
- [ ] **Step 2:** Replace the bodies with sums over `idsIncludedInAllItemsForUser`, `idsPerTagScopeForUser` (tags with a zero sum stay absent, as today) and `idsForUserInFeedGroup`; delete `taggedUnreadEntries`, `unreadInFeedGroup`, `countedDistinctBy`.
- [ ] **Step 3:** `SubscriptionTallyReader` reads `perSubscription()` once and hands it to the three.
- [ ] **Step 4:** Both suite legs, `composer check`, `composer md`.
- [ ] **Step 5:** Measure `/api/subscriptions` and `/api/subscriptions/counts` on the Docker lab account (prod kernel), before and after, median of 5; numbers go in the PR and on #2159. Strato follows a deploy.
