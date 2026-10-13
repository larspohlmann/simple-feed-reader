// Shared reader-endpoint fixtures for the article e2e specs.
import type { Page } from '@playwright/test';
import type {
  EntryDto,
  SavedSearchWire,
  SubscriptionDto,
  SubscriptionsResponse,
} from '../../src/app/reader/models';
import type { EntryDetailResponse } from '../../src/app/reader/state/completed-entry';
import { anEntry } from '../../src/testing/entry-testing';
import { aSubscription } from '../../src/testing/subscription-testing';
import { stubAuthToken } from './auth';

/** Reasons the backend reports when reader extraction produces no article. */
type ReaderFailureReason = 'no_url' | 'fetch' | 'unextractable' | 'empty';

/**
 * A failed `GET /api/entries/{id}/reader` body that matches the current
 * contract. Since #592 both heroes ride on both branches (see backend
 * `ReaderJson`), so the client reads `originalHero` whatever the status. A stub
 * that omits it leaves the field undefined and the reader view's `hero` computed
 * throws on `.url` — the article then hangs on "Loading…" with empty content.
 * Kept in one place so every article spec forces the same, contract-true shape
 * and the six copies can never drift from the DTO one at a time again.
 */
export function readerFailedJson(reason: ReaderFailureReason = 'fetch') {
  return { status: 'failed' as const, url: null, reason, readerHero: null, originalHero: null };
}

/**
 * A `GET /api/saved-searches` body that matches the current contract.
 *
 * The store does not read a count off the wire — it derives one from
 * `unreadEntryIds` so a read entry drops the badge without another round-trip
 * (#645). A stub that still sends the old scalar `unreadCount` leaves that
 * array undefined, `savedSearches()` throws on `.reduce`, and the whole reader
 * shell renders empty — which reads as a layout bug three assertions later
 * rather than as a stale fixture (#718).
 *
 * Typed as `SavedSearchWire`, so the next field added to the DTO is a red
 * squiggle here instead of a runtime throw in whichever spec fills the array.
 * A spec that needs no saved searches keeps passing `[]` — that path never
 * touches the shape and never rots.
 */
export function savedSearchesJson(...savedSearches: SavedSearchWire[]) {
  return { savedSearches };
}

/** One saved search, contract-true, with the fields a spec cares about
 *  overridable. `unreadEntryIds` drives the sidebar badge. */
export function savedSearchWire(overrides: Partial<SavedSearchWire> = {}): SavedSearchWire {
  const merged = {
    id: 1,
    term: 'number',
    wholeWord: false,
    phrase: false,
    position: 0,
    unreadEntryIds: [1, 2, 3, 4, 5],
    memberCount: 5,
    includeInDigest: false,
    ...overrides,
  };
  return { ...merged, slug: overrides.slug ?? `${merged.id}-${merged.term}` };
}

/** A signed-in account with one feed and empty lists: every API read the reader
 *  shell boots with, stubbed, so a list-header spec needs no Docker data. */
export async function stubOneFeedReader(
  page: Page,
  feedTitle: string,
  savedSearches: SavedSearchWire[] = [],
): Promise<void> {
  await stubAuthToken(page);
  const json = (body: unknown) => (route: { fulfill: (response: { json: unknown }) => unknown }) =>
    route.fulfill({ json: body });

  await page.route('**/api/subscriptions**', json(oneFeedJson(feedTitle)));
  await page.route('**/api/tags**', json({ tags: [] }));
  await page.route('**/api/entries**', json({ entries: [], nextCursor: null }));
  await page.route('**/api/me**', json({ id: 1, email: '', roles: [], preferences: {} }));
  await page.route('**/api/version**', json({ version: 'dev' }));
  await page.route('**/api/recommendations/**', json({ run: null }));
  await page.route('**/api/saved-searches**', json(savedSearchesJson(...savedSearches)));
}

/** One list entry, contract-true, with the fields a spec cares about overridable. */
export function entryWire(overrides: Partial<EntryDto> & Pick<EntryDto, 'id'>): EntryDto {
  return anEntry({
    title: `Fixture entry ${overrides.id}`,
    url: `https://fixtures.invalid/${overrides.id}`,
    summary: 'A fixture summary, long enough to give the row some height.',
    excerpt: 'Fixture body.',
    publishedAt: '2026-08-01T12:50:34+00:00',
    createdAt: '2026-08-01T12:50:34+00:00',
    source: 'Fixture feed',
    ...overrides,
  });
}

/** A `GET /api/entries/{id}` body: the list entry plus its full article (#1100), and what completing it
 *  filled in (#1551). */
export function entryDetailJson(entry: EntryDto, contentHtml: string): EntryDetailResponse {
  const { id, pending, summary, excerpt, imageUrl, imageWidth, imageHeight } = entry;
  const { imageRenditions, media, linkCards, share } = entry;
  const completed = { id, pending, summary, excerpt, imageUrl, imageWidth, imageHeight };
  return {
    entry: { ...entry, contentHtml },
    completed: { ...completed, imageRenditions, media, linkCards, share },
  };
}

/**
 * A `GET /api/subscriptions` body that matches the current contract.
 *
 * Typed as `SubscriptionsResponse`: a stub that misses a field the shell reads
 * (`socialAccounts` since #1599) throws inside a computed and renders an empty
 * reader, which reads as a layout bug rather than a stale fixture.
 */
export function subscriptionsJson(
  subscriptions: SubscriptionDto[],
  totals: Partial<Omit<SubscriptionsResponse, 'subscriptions'>> = {},
): SubscriptionsResponse {
  return {
    subscriptions,
    favoritesCount: 0,
    keptCount: 0,
    viewedCount: 0,
    socialAccounts: [],
    ...totals,
  };
}

/** A `GET /api/subscriptions` body with one feed; `overrides` reshapes that feed. */
export function oneFeedJson(
  title: string,
  overrides: Partial<SubscriptionDto> = {},
): SubscriptionsResponse {
  return subscriptionsJson([aSubscription({ title, unreadCount: 1, entryCount: 1, ...overrides })]);
}
