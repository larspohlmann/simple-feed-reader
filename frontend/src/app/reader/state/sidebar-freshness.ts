/**
 * How fresh the sidebar counts have to be — THE single decision behind both
 * halves of the rule (#708):
 *   - the poll ticks this often, so a count is never more than one interval
 *     behind the server;
 *   - a store refuses to refetch within one interval of its last load, so a
 *     tick right after an action-triggered load isn't spent on a stale number.
 *
 * One constant: a mismatched interval and freshness window means either a
 * wasted request every tick or a count that never reaches the screen. Gates
 * `SubscriptionsStore` and `SavedSearchesStore` alike; heading and tab title
 * follow those stores (#709), so every surface sharing a count shares this rule.
 */
export const SIDEBAR_RELOAD_INTERVAL_MS = 30_000;

/** Has `lastLoadedAt` fallen out of the freshness window? A store that has
 *  never loaded (0) is stale by definition. */
export function countsAreStale(lastLoadedAt: number): boolean {
  return Date.now() - lastLoadedAt >= SIDEBAR_RELOAD_INTERVAL_MS;
}
