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

const LONG_TEXT = 60;
const TEXT_HERO = 110;
const GROUP_MIN = 3;
const GROUP_SHOW = 3;
const HERO_PERIOD = 4;

/** Turn the ordered entry list into a varied block sequence. `grouping` is true
 *  in aggregated views (All / tag / favorites / kept) and false in a single-feed
 *  view, where every entry shares a source. `complete` is false while more pages
 *  can still load (i.e. `hasMore`); it defaults to true.
 *
 *  Large entries come from two sources: every source GROUP is led by a hero (its
 *  top story big, the rest as a small cluster + "more" link), and an ungrouped
 *  run promotes a hero on a beat (~every HERO_PERIOD blocks; an entry with an
 *  image or long text can lead a block sooner). Two heroes never sit adjacent.
 *
 *  Prefix-stable: re-running over a longer list never rewrites an already-emitted
 *  block. A short trailing same-source run (1–2 entries) that a later page could
 *  grow into a group is HELD back while `!complete`; a run that is already a group
 *  only gains `moreCount` on growth (same key, same lead + first-shown entries). */
export function planMagazine(
  entries: EntryDto[],
  grouping: boolean,
  complete = true,
): MagazineBlock[] {
  const blocks: MagazineBlock[] = [];
  let sinceHero = HERO_PERIOD; // let the first block lead as a hero
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
        // Lead the group with a hero unless that would sit two heroes adjacent.
        if (!lastWasHero) {
          blocks.push({ kind: 'hero', entry: entries[i] });
          blocks.push(groupBlock(entries.slice(i + 1, i + run)));
          sinceHero = 1;
        } else {
          blocks.push(groupBlock(entries.slice(i, i + run)));
          sinceHero += 1;
        }
        lastWasHero = false;
        i += run;
        continue;
      }
    }

    const entry = entries[i];
    const hasImage = firstPreviewImage(entry.contentHtml, entry.summary) !== null;
    const textLen = entry.title.length + textSnippet(entry.summary || entry.contentHtml).length;
    const eligible = hasImage ? textLen >= LONG_TEXT : textLen >= TEXT_HERO;
    const heroFires = !lastWasHero && sinceHero >= (eligible ? HERO_PERIOD - 1 : HERO_PERIOD);

    if (heroFires) {
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

function groupBlock(items: EntryDto[]): MagazineBlock {
  const shown = Math.min(items.length, GROUP_SHOW);
  return {
    kind: 'group',
    subscriptionId: items[0].subscriptionId,
    source: items[0].source,
    entries: items.slice(0, shown),
    moreCount: items.length - shown,
  };
}

function sameSourceRun(entries: EntryDto[], start: number): number {
  const sub = entries[start].subscriptionId;
  let n = 1;
  while (start + n < entries.length && entries[start + n].subscriptionId === sub) n += 1;
  return n;
}
