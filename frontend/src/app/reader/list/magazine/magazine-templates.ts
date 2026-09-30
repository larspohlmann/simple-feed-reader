import { EntryKind } from './magazine-block';

/** A slot is either a fixed kind, or one of two kinds chosen by seeded jitter —
 *  jitter keeps a library this small from reading as a cycle: twelve templates
 *  alone repeat every ~66 blocks, findable in one sitting. */
export type Slot = EntryKind | { either: [EntryKind, EntryKind] };

/**
 * The rhythm, as data.
 *
 * Authored, not derived. Content-driven sizing was simulated against 300 real
 * entries and produced the MOST monotonous output of three candidates (3-gram
 * entropy 1.57 vs 4.88 for templates): once the image pipeline works, ~99% of
 * entries score the same, so every entry gets the same block. Variety lives
 * here; content only decides whether an entry can FILL the slot it is given.
 *
 * TWO families exist for two kinds of feed. An image-rich view (a magazine, a
 * photo blog) wants IMAGE_TEMPLATES. A wire service ships only tiny thumbnails
 * and long copy, collapsing every large-image slot to `thumb`, so an
 * image-poor view uses TEXT_TEMPLATES instead — a headline-and-pull-quote
 * rhythm. The planner picks between them from the leading window's image economy.
 *
 * Rules when editing:
 * - Vary length (4–6). Equal-length templates re-introduce a beat.
 * - At most one `hero` per template, and never as the last slot.
 * - Keep the mixed heights: an all-large template costs a whole screenful.
 * - Add templates rather than lengthening them.
 * - Keep each family's length coprime with the stride (5) in `templateFor`.
 */
export const IMAGE_TEMPLATES: readonly (readonly Slot[])[] = [
  ['hero', 'split', 'thumb', { either: ['thumb', 'split'] }, 'split'],
  // Index 1 is the opener (templateFor(0)) — front-loaded with image blocks so
  // a reader lands on pictures. `thumb`/`split` fillers are ADAPTIVE: an
  // image-less entry demotes to `compact` on its own; `compact` is never
  // authored directly.
  ['wide', 'split', 'thumb', 'split', 'thumb', 'split'],
  ['split', 'thumb', 'hero', { either: ['thumb', 'split'] }, 'split'],
  ['hero', 'thumb', 'split', 'quote', 'thumb'],
  ['wide', 'thumb', 'split', 'thumb', 'split', 'thumb'],
  ['split', 'thumb', 'hero', 'thumb', { either: ['split', 'thumb'] }, 'wide'],
  ['hero', 'split', 'thumb', 'thumb', 'kicker'],
  ['kicker', 'split', 'thumb', 'wide', 'thumb'],
  ['split', 'hero', 'thumb', { either: ['split', 'thumb'] }, 'thumb'],
  ['wide', 'thumb', 'quote', 'split', 'thumb', 'split'],
  ['split', 'wide', 'thumb', 'split', 'thumb'],
  ['hero', 'thumb', 'split', 'thumb', 'quote', 'kicker'],
];

/**
 * The image-poor rhythm: pull-quotes, headline bands, and the feed's own small
 * thumbnails. A wire service (Phys.org, Reuters) is image-poor but TEXT-rich, so
 * this reads as a text magazine, not a degraded image one. `hero`/`wide`/`split`
 * are absent — a 90px thumbnail can't fill them and would collapse to `thumb`.
 */
export const TEXT_TEMPLATES: readonly (readonly Slot[])[] = [
  ['quote', 'thumb', 'kicker', { either: ['thumb', 'compact'] }, 'compact'],
  // Index 1 is the opener: a headline band leads, then a photo and a pull-quote —
  // a strong text-forward start for a feed with no large images to lead on.
  ['kicker', 'thumb', 'quote', 'compact', 'thumb', 'kicker'],
  ['thumb', 'kicker', 'compact', 'quote', { either: ['thumb', 'kicker'] }],
  ['quote', 'thumb', 'compact', 'kicker', 'thumb', 'compact'],
  ['kicker', { either: ['thumb', 'quote'] }, 'quote', 'thumb', 'compact'],
  ['thumb', 'compact', 'quote', 'kicker', 'thumb', 'thumb'],
  ['quote', 'kicker', 'thumb', 'compact', { either: ['thumb', 'kicker'] }],
];
