import { RunGroup } from '../for-you-runs';
import { MagazineBlock } from '../magazine/magazine-block';

/** The run divider the list interleaves between per-run magazine block groups
 *  (#348). Kept out of MagazineBlock so the planner, which never emits it,
 *  stays unaware. */
export interface RunHeaderBlock {
  kind: 'run-header';
  generatedAt: string;
}

/** Where a followed story's rows that joined since the last visit end (#2128). */
export interface SinceVisitBlock {
  kind: 'since-last-visit';
}

export const SINCE_VISIT_BLOCK: SinceVisitBlock = { kind: 'since-last-visit' };

/** What the magazine branch actually renders: planner blocks plus dividers. */
export type ListBlock = MagazineBlock | RunHeaderBlock | SinceVisitBlock;

/** A block's identity across re-plans: a divider by what it marks, a block by its first entry. */
export function listBlockKey(block: ListBlock): string {
  if (block.kind === 'run-header') return `run-header:${block.generatedAt}`;
  if (block.kind === 'since-last-visit') return block.kind;
  return block.kind === 'group'
    ? `g${block.subscriptionId}:${block.entries[0].id}`
    : `${block.kind}:${block.entry.id}`;
}

function isDivider(block: ListBlock): block is RunHeaderBlock | SinceVisitBlock {
  return block.kind === 'run-header' || block.kind === 'since-last-visit';
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

/** A divider goes once nothing is left below it, and the visit's once nothing new is left above it. */
function withoutEmptyRuns(blocks: ListBlock[]): ListBlock[] {
  return blocks.filter((block, index) => {
    if (!isDivider(block)) return true;
    const next = blocks[index + 1];
    if (next === undefined || isDivider(next)) return false;
    return block.kind === 'run-header' || index > 0;
  });
}

/** `groups` minus the hidden ids — the list layout's counterpart to `blocksWithout`. */
export function runGroupsWithout(groups: RunGroup[], hidden: ReadonlySet<number>): RunGroup[] {
  if (hidden.size === 0) return groups;
  return groups
    .map((group) => {
      const entries = group.entries.filter((entry) => !hidden.has(entry.id));
      return entries.length === group.entries.length ? group : { ...group, entries };
    })
    .filter((group) => group.entries.length > 0);
}
