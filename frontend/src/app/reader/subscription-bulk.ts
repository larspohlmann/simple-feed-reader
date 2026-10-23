/** The per-feed inclusion switches, as a bulk change. An omitted field
 *  leaves the stored value alone — the API's null-means-unchanged convention. */
export interface SubscriptionFlags {
  includeInAllItems?: boolean;
  includeInForYou?: boolean;
  includeInSavedSearches?: boolean;
}

/** One bulk change across many feeds. At most one tag is added and at most one
 *  removed per request; the page never needs more, and a single tag keeps the
 *  confirmation text exact. */
export interface BulkSubscriptionUpdate extends SubscriptionFlags {
  subscriptionIds: number[];
  addTagIds?: number[];
  removeTagIds?: number[];
}
