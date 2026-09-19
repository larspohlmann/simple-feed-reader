import { RunGroup } from '../for-you-runs';
import { MagazineBlock } from '../magazine/magazine-block';

/** The run divider the list interleaves between per-run magazine block groups
 *  (#348). Kept out of MagazineBlock so the planner, which never emits it,
 *  stays unaware. */
export interface RunHeaderBlock {
  kind: 'run-header';
  generatedAt: string;
}

/** What the magazine branch actually renders: planner blocks plus run dividers. */
export type ListBlock = MagazineBlock | RunHeaderBlock;

/** `blocks` minus the hidden ids, applied to the planner's OUTPUT so nothing
 *  re-plans (#1080): a group keeps its surviving members, and a run divider left
 *  with no block goes too. The input itself comes back when nothing is hidden. */
export function blocksWithout(blocks: ListBlock[], hidden: ReadonlySet<number>): ListBlock[] {
  if (hidden.size === 0) return blocks;
  return withoutEmptyRuns(blocks.flatMap((block) => blockWithout(block, hidden)));
}

function blockWithout(block: ListBlock, hidden: ReadonlySet<number>): ListBlock[] {
  if (block.kind === 'run-header') return [block];
  if (block.kind !== 'group') return hidden.has(block.entry.id) ? [] : [block];
  const entries = block.entries.filter((e) => !hidden.has(e.id));
  if (entries.length === 0) return [];
  return entries.length === block.entries.length ? [block] : [{ ...block, entries }];
}

function withoutEmptyRuns(blocks: ListBlock[]): ListBlock[] {
  return blocks.filter((block, index) => {
    if (block.kind !== 'run-header') return true;
    const next = blocks[index + 1];
    return next !== undefined && next.kind !== 'run-header';
  });
}

/** `groups` minus the hidden ids — the list layout's counterpart to `blocksWithout`. */
export function runGroupsWithout(groups: RunGroup[], hidden: ReadonlySet<number>): RunGroup[] {
  if (hidden.size === 0) return groups;
  return groups
    .map((group) => {
      const entries = group.entries.filter((e) => !hidden.has(e.id));
      return entries.length === group.entries.length ? group : { ...group, entries };
    })
    .filter((group) => group.entries.length > 0);
}
