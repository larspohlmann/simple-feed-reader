import { MagazineBlock } from '../magazine/magazine-block';

/** The run divider the list interleaves between per-run magazine block groups
 *  (#348). Kept out of MagazineBlock so the planner, which never emits it,
 *  stays unaware. */
export interface RunHeaderBlock {
  kind: 'run-header';
  generatedAt: string;
}

/** A followed story's pair of markers: above the rows that joined since the last visit, and where they
 *  end (#2128, #2505). Drawn together or not at all. */
export interface VisitBlock {
  kind: 'since-last-visit' | 'before-last-visit';
}

export const SINCE_VISIT_BLOCK: VisitBlock = { kind: 'since-last-visit' };
export const BEFORE_VISIT_BLOCK: VisitBlock = { kind: 'before-last-visit' };

/** What the magazine branch actually renders: planner blocks plus dividers. */
export type ListBlock = MagazineBlock | RunHeaderBlock | VisitBlock;

/** A block's identity across re-plans: a divider by what it marks, a block by its first entry. */
export function listBlockKey(block: ListBlock): string {
  if (block.kind === 'run-header') return `run-header:${block.generatedAt}`;
  if (isVisitBlock(block)) return block.kind;
  return block.kind === 'group'
    ? `g${block.subscriptionId}:${block.entries[0].id}`
    : `${block.kind}:${block.entry.id}`;
}

export function isVisitBlock(block: { kind: string } | null): block is VisitBlock {
  return block?.kind === 'since-last-visit' || block?.kind === 'before-last-visit';
}

/** Both visit markers are still there, so each has rows below it. */
export function marksVisit(markers: ({ kind: string } | null)[]): boolean {
  const kinds = markers.filter(isVisitBlock).map((marker) => marker.kind);
  return kinds.includes('since-last-visit') && kinds.includes('before-last-visit');
}

function isDivider(block: ListBlock): block is RunHeaderBlock | VisitBlock {
  return block.kind === 'run-header' || isVisitBlock(block);
}

/** `blocks` minus the hidden ids, applied to the planner's OUTPUT so nothing
 *  re-plans (#1080): a group keeps its surviving members, and a divider left
 *  with no block goes too. The input itself comes back when nothing is hidden. */
export function blocksWithout(blocks: ListBlock[], hidden: ReadonlySet<number>): ListBlock[] {
  if (hidden.size === 0) return blocks;
  return withoutEmptyRuns(blocks.flatMap((block) => blockWithout(block, hidden)));
}

/** `blocks` with the leaving ids taken out of group widgets only: a single-entry
 *  block animates out in its slot, a group's member leaves its widget at once. */
export function groupMembersWithout(
  blocks: ListBlock[],
  leaving: ReadonlySet<number>,
): ListBlock[] {
  if (leaving.size === 0) return blocks;
  return withoutEmptyRuns(
    blocks.flatMap((block) => (block.kind === 'group' ? blockWithout(block, leaving) : [block])),
  );
}

function blockWithout(block: ListBlock, hidden: ReadonlySet<number>): ListBlock[] {
  if (isDivider(block)) return [block];
  if (block.kind !== 'group') return hidden.has(block.entry.id) ? [] : [block];
  const entries = block.entries.filter((entry) => !hidden.has(entry.id));
  if (entries.length === 0) return [];
  return entries.length === block.entries.length ? [block] : [{ ...block, entries }];
}

/** A divider goes once nothing is left below it, and the visit's pair once either one goes. */
function withoutEmptyRuns(blocks: ListBlock[]): ListBlock[] {
  const kept = blocks.filter((block, index) => {
    if (!isDivider(block)) return true;
    const next = blocks[index + 1];
    return next !== undefined && !isDivider(next);
  });
  return marksVisit(kept) ? kept : kept.filter((block) => !isVisitBlock(block));
}
