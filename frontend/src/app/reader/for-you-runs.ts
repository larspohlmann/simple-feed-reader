// src/app/reader/for-you-runs.ts
import { EntryDto } from './models';

/** One contiguous block of for-you entries that share a recommendation run. */
export interface RunGroup {
  /** The run's id; undefined for non-for-you entries. Used only as a stable key. */
  runId: number | undefined;
  /** The run's generation time (ISO); undefined for non-for-you entries. */
  generatedAt: string | undefined;
  entries: EntryDto[];
}

/** Partition a for-you page into one group per run, preserving order. The API
 *  returns entries run-contiguous (newest run first, position within a run), so
 *  a change in `runId` between neighbours is exactly a run boundary. Entries with
 *  no `runId` (every non-for-you view) collapse into a single run-less group. */
export function groupByRun(entries: EntryDto[]): RunGroup[] {
  const groups: RunGroup[] = [];
  for (const entry of entries) {
    const current = groups[groups.length - 1];
    if (current && current.runId === entry.runId) {
      current.entries.push(entry);
      continue;
    }
    groups.push({ runId: entry.runId, generatedAt: entry.runGeneratedAt, entries: [entry] });
  }
  return groups;
}
