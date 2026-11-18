import { ListItem, leadOf } from '../state/list-item';

/** One contiguous block of for-you items that share a recommendation run. */
export interface RunGroup {
  /** The run's id; undefined for non-for-you items. Used only as a stable key. */
  runId: number | undefined;
  /** The run's generation time (ISO); undefined for non-for-you items. */
  generatedAt: string | undefined;
  items: ListItem[];
}

/** Partition a for-you page into one group per run, preserving order. The API
 *  returns entries run-contiguous (newest run first, position within a run), so
 *  a change in `runId` between neighbours is exactly a run boundary. Items whose
 *  lead has no `runId` (every non-for-you view) collapse into a single run-less group. */
export function groupByRun(items: ListItem[]): RunGroup[] {
  const groups: RunGroup[] = [];
  for (const item of items) {
    const { runId, runGeneratedAt } = leadOf(item);
    const current = groups[groups.length - 1];
    if (current && current.runId === runId) {
      current.items.push(item);
      continue;
    }
    groups.push({ runId, generatedAt: runGeneratedAt, items: [item] });
  }
  return groups;
}
