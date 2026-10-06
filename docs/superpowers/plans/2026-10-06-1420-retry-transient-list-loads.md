# #1420 Retry list loads on 502/503/504 — implementation plan

**Goal:** an entry-list load, and the sidebar's subscriptions, counts and saved-search requests, retry a
502, 503 or 504 after 2 s, 5 s and 10 s before the error reaches the banner. Any other error surfaces at
once. The banner's manual retry stays as the fallback.

**Design**

- `core/http/retry-transient-gateway-errors.ts` exports an rxjs operator
  `retryTransientGatewayErrors<T>()`: `retry({ count: 3, delay })`, where `delay` re-throws anything
  that is not an `HttpErrorResponse` with status 502/503/504 and otherwise waits
  `TRANSIENT_RETRY_DELAYS_MS[retryCount - 1]` (2000, 5000, 10000).
- `ReaderApi` pipes it onto `entries()`, `subscriptions()`, `subscriptionCounts()` and
  `savedSearches()`, so every caller of those reads gets it from one place. Writes are never retried.
- `EntriesStore` keeps the subscription of its current list request and unsubscribes it when a newer
  `load()` starts or the identity changes, so a retry pending for a list the user has left is cancelled
  rather than fired and discarded. `SubscriptionsStore` and `SavedSearchesStore` already unsubscribe a
  superseded request.

**Tasks (TDD, Jest with fake timers)**

1. Operator spec: a 504 then success emits the value after 2 s with two requests; three 503s then a
   success needs 2 + 5 + 10 s; four 502s surface the fourth error; a 500 and a 404 surface at once with
   one request.
2. `EntriesStore` spec: a 504 load shows no banner while retrying and lands the page on the retry; four
   504s show the banner after the last one (4 requests); switching lists while a retry is pending sends
   no stale request and lands the new list.
3. `ReaderApi` spec: subscriptions, counts and saved searches retry a 503.
4. Gates: `npm run check` inside the Docker frontend container.
