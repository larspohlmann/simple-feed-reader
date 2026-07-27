// src/app/reader/magazine/magazine-templates.ts
import { EntryKind } from './magazine-block';

/** A slot is either a fixed kind, or one of two kinds chosen by seeded jitter.
 *  Jitter is what keeps a library this small from reading as a cycle: twelve
 *  templates alone repeat every ~66 blocks, which is findable within a single
 *  sitting. */
export type Slot = EntryKind | { either: [EntryKind, EntryKind] };

/**
 * The rhythm, as data.
 *
 * Authored, not derived. Content-driven sizing was simulated against 300 real
 * entries and produced the MOST monotonous output of three candidates
 * (3-gram entropy 1.57 vs 4.88 for templates): once the image pipeline works,
 * ~99% of entries carry a large image and a usable snippet, so every entry
 * scores the same and every entry gets the same block. Variety therefore lives
 * here, and content only decides whether an entry can FILL the slot it is given.
 *
 * Rules when editing:
 * - Vary length (4–6). Equal-length templates re-introduce a beat.
 * - At most one `hero` per template, and never as the last slot.
 * - Keep the mixed heights: an all-large template costs a whole screenful.
 * - Add templates rather than lengthening them.
 */
export const TEMPLATES: readonly (readonly Slot[])[] = [
  ['hero', 'split', 'compact', 'compact', { either: ['thumb', 'compact'] }],
  ['wide', 'quote', 'split', 'compact', 'compact', 'compact'],
  ['split', 'thumb', 'hero', 'compact', 'compact'],
  ['quote', 'split', 'split', 'compact', 'wide'],
  ['hero', 'compact', 'compact', { either: ['thumb', 'compact'] }, 'thumb', 'split'],
  ['wide', 'split', 'compact', 'quote', { either: ['compact', 'thumb'] }],
  ['split', 'compact', 'hero', 'thumb', 'compact', 'compact'],
  ['kicker', 'split', 'thumb', 'compact', 'wide'],
  ['hero', 'thumb', 'compact', 'split', 'compact'],
  ['quote', 'compact', 'wide', 'compact', { either: ['split', 'thumb'] }, 'compact'],
  ['split', 'wide', 'compact', 'compact', 'thumb'],
  ['kicker', 'compact', 'split', 'hero', 'compact', 'compact'],
];
