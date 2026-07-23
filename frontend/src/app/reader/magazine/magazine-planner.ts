// src/app/reader/magazine/magazine-planner.ts
import { EntryDto } from '../models';
import { firstPreviewImage, textSnippet } from '../preview-image';

export type MagazineBlock =
  | { kind: 'hero'; entry: EntryDto }
  | { kind: 'feature'; entry: EntryDto; imageSide: 'left' | 'right' }
  | { kind: 'compact'; entry: EntryDto }
  | {
      kind: 'group';
      subscriptionId: number;
      source: string;
      entries: EntryDto[];
      moreCount: number;
    };

const LONG_TEXT = 120;
const TEXT_HERO = 180;
const GROUP_MIN = 3;
const GROUP_SHOW = 3;
const HERO_PERIOD = 6;

/** Turn the ordered entry list into a varied block sequence. `grouping` is true
 *  in aggregated views (All / tag / favorites / kept) and false in a single-feed
 *  view, where every entry shares a source. `complete` is false while more pages
 *  can still load (i.e. `hasMore`); it defaults to true.
 *
 *  Prefix-stable: re-running over a longer list never rewrites an already-emitted
 *  block. The one case that could — a short trailing same-source run (1–2 entries)
 *  that a later page grows into a group — is HELD back while `!complete`, so those
 *  entries stay unplanned until the page that completes the run arrives and they
 *  are emitted once. A trailing run that is already a group (≥3) only ever gains
 *  `moreCount` on growth (same key, same first-3 entries), which is a label update,
 *  not a reshuffle. */
export function planMagazine(
  entries: EntryDto[],
  grouping: boolean,
  complete = true,
): MagazineBlock[] {
  const blocks: MagazineBlock[] = [];
  let sinceHero = HERO_PERIOD; // let an eligible entry near the top lead
  let lastWasHero = false;
  let featureCount = 0;

  let i = 0;
  while (i < entries.length) {
    if (grouping) {
      const run = sameSourceRun(entries, i);
      // Hold a short trailing run: a later page could extend it past GROUP_MIN
      // and turn these individual blocks into a group, rewriting what the user
      // is already looking at mid-scroll.
      if (!complete && i + run === entries.length && run < GROUP_MIN) break;
      if (run >= GROUP_MIN) {
        const slice = entries.slice(i, i + run);
        blocks.push({
          kind: 'group',
          subscriptionId: slice[0].subscriptionId,
          source: slice[0].source,
          entries: slice.slice(0, GROUP_SHOW),
          moreCount: run - GROUP_SHOW,
        });
        i += run;
        sinceHero = 0; // a group is itself a change of pace
        lastWasHero = false;
        continue;
      }
    }

    const entry = entries[i];
    const hasImage = firstPreviewImage(entry.contentHtml, entry.summary) !== null;
    const textLen = entry.title.length + textSnippet(entry.summary || entry.contentHtml).length;
    const heroEligible = hasImage ? textLen >= LONG_TEXT : textLen >= TEXT_HERO;

    if (heroEligible && !lastWasHero && sinceHero >= HERO_PERIOD - 1) {
      blocks.push({ kind: 'hero', entry });
      sinceHero = 0;
      lastWasHero = true;
    } else if (hasImage) {
      blocks.push({ kind: 'feature', entry, imageSide: featureCount % 2 === 0 ? 'right' : 'left' });
      featureCount += 1;
      sinceHero += 1;
      lastWasHero = false;
    } else {
      blocks.push({ kind: 'compact', entry });
      sinceHero += 1;
      lastWasHero = false;
    }
    i += 1;
  }
  return blocks;
}

function sameSourceRun(entries: EntryDto[], start: number): number {
  const sub = entries[start].subscriptionId;
  let n = 1;
  while (start + n < entries.length && entries[start + n].subscriptionId === sub) n += 1;
  return n;
}
