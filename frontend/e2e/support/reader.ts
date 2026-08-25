// Shared reader-endpoint fixtures for the article e2e specs.

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
