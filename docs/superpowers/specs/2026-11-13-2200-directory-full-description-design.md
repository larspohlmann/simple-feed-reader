# Feed directory: a row grows to show its full description (#2200)

## Intent

The directory cuts each site's description off after two lines because the list is
a fixed-height virtual scroll. Lars wants to read the whole description in the row
itself: the row grows with its text.

## Goals

- Every row shows its full description, with no line clamp, on phones and on wide
  screens. The row is as tall as its content.
- The list stays quick to open and to filter, even with about 8k sites.

## Non-goals

- No change to what a row shows apart from the description, and no change to the
  filters, the sort or the subscribe flow.

## Approaches

1. **Natural-height rows, rendered in batches (chosen).** Drop the CDK virtual
   scroll. Show the first batch of rows, and add the next batch when a sentinel
   below the list comes within reach of the viewport. This is the reader list's
   pattern (`reader/list/paging.ts`: `prefetchMargin`, an IntersectionObserver on a
   sentinel). The cost: the DOM keeps every row scrolled past, which only matters
   at thousands of rows.
2. CDK's experimental autosize virtual scroll. Rejected: it's a new experimental
   dependency, known for jumpy scrolling, especially with window scrolling.
3. Virtual scroll with heights estimated by measuring the text. Rejected: the most
   code, and an estimate that's off by one line makes rows overlap or leave gaps.

## Design

- `FeedDirectorySectionComponent` renders `shownRows()` with a plain `@for`, tracked
  by host, in place of `cdk-virtual-scroll-viewport`. `shownRows()` is
  `store.rows()` cut to the first `limit()` rows. The CDK scrolling imports,
  `rowHeight()`, `--row-h` and `FEED_DIRECTORY_ROW_HEIGHT` go away.
- A batch is **100 rows**, the reader's `PAGE_SIZE`. A sentinel element after the
  list is watched by an IntersectionObserver with the viewport as root (the page
  scrolls the window) and `rootMargin: prefetchMargin(window.innerHeight)`. When it
  intersects and more rows are left, `limit` grows by one batch.
- **`limit` resets to one batch when the result set changes: mode, categories,
  lengths, languages, query, country, sort, or a new load. It does not reset when
  only the subscription marks change.** `store.rows()` gets a new value whenever a
  subscription is added (each row's `subscribed` and `following` marks are
  recomputed). Resetting then would collapse the list under someone who just
  subscribed from row 300.
- Row SCSS: drop `height: var(--row-h)`, `overflow: hidden` and the summary's line
  clamp. The row keeps its padding and grid. The phone layout from #2196 stays
  (the pills sit under the summary).

## Tests

- The section shows the first 100 rows and adds the next 100 when the sentinel
  intersects (with IntersectionObserver stubbed in jsdom).
- A filter change goes back to 100. A subscription change (a new
  `subscriptions()` value) keeps the current limit.
- The row's summary has no clamp. That is CSS, so it's checked live on :4200:
  long descriptions show in full on phones and on desktop, and scrolling to the
  bottom keeps loading batches.
- The existing "row height per breakpoint" section test is deleted along with
  the fixed heights.
