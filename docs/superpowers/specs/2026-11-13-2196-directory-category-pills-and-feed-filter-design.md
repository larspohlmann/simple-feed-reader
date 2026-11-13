# Feed directory: category pills on phones, category filter narrows the feed list (#2196)

## Intent

On a phone the directory hides each site's category pills, so you can't see or
tap a site's categories there. When a category filter is on, a site's feed
list still offers every feed the site has, including ones in other
categories. Both changes make the category filter work on phones the way it
works on wide screens.

## Goals

- Phone rows show the category pills under the summary. The pills work as they
  do on wide screens: tapping one shows only that category, tapping it again
  clears the filter.
- With one or more categories chosen, a site's feed list (the popover on a fine
  pointer, the action sheet on a coarse one) shows only the feeds in those
  categories.
- The row's button follows the same narrowing. "N feeds" counts only matching
  feeds, "· N subscribed" counts only matching subscribed feeds, and exactly
  one matching feed turns the button into a direct Subscribe (or Subscribed)
  button.

## Non-goals

- The pills keep showing all of the site's categories for the current mode,
  not only the chosen ones, so a row can still switch the filter.
- No change to which sites match the filters, or to the counts in the toolbar.

## Approaches

1. **One shared helper, read in two places (chosen).** A pure function in
   `feed-directory-rows.ts` returns a site's feeds for the current mode,
   narrowed to the chosen categories. The row gets the chosen categories as an
   input. `FeedDirectorySubscriber` calls the same helper with
   `store.categories()`.
2. The store attaches the narrowed feeds to every row. Rejected: every filter
   change would create new row objects, which undoes the store's reuse of
   unchanged rows. It would also mix "which sites match" with "which feeds to
   offer".

## Design

- `feed-directory-rows.ts`: a function `shownFeeds(row, mode, categories)`
  returns `feedsIn(row, mode)` when no category is chosen, and otherwise only
  the feeds whose category is in `categories`.
- `FeedDirectoryRowComponent`: a new `categories` input
  (`ReadonlySet<string>`, empty by default). `feeds()` uses `shownFeeds`. The
  pills come from `feedsIn` (all of the site's categories in this mode).
- `FeedDirectorySectionComponent` template passes `store.categories()`.
- `FeedDirectorySubscriber.open` uses `shownFeeds(row, store.mode(),
  store.categories())`.
- Phone layout: show `.chips` below `bp-sm`, spanning the full row width
  (`grid-column: 1 / -1`). Set `FEED_DIRECTORY_ROW_HEIGHT.phone` from a
  measurement of the real render on :4200 (about 142, up from 114). The
  "· N subscribed" part stays hidden on phones.

## Tests

- `shownFeeds`: with no categories, with one category, with two.
- Row spec: the count, the single-feed shortcut and the subscribed count follow
  `categories`; the pills still list every category.
- Subscriber spec (or the section spec, if it has one): the popover and sheet
  receive only the narrowed feeds.
