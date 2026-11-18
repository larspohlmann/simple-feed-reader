import { BlockCard } from './magazine-block';

/** A same-source run, with any single foreign entries it bridges pulled aside. */
export interface DetectedRun {
  source: number;
  sourceCards: BlockCard[];
  interlopers: BlockCard[];
  /** Exclusive index in `ordered` where the run's span ends. */
  end: number;
}

/** Walk a same-source run from `start`, bridging any single foreign entry the
 *  source resumes right after (`Dom…X…Dom`); a gap of two or more ends the run.
 *  Every entry in `[start, end)` is same-source or a bridged interloper. */
export function detectRun(ordered: BlockCard[], start: number): DetectedRun {
  const source = ordered[start].entry.subscriptionId;
  const sourceCards: BlockCard[] = [];
  const interlopers: BlockCard[] = [];
  let index = start;
  while (index < ordered.length) {
    if (ordered[index].entry.subscriptionId === source) {
      sourceCards.push(ordered[index]);
      index += 1;
      continue;
    }
    const bridges =
      index + 1 < ordered.length && ordered[index + 1].entry.subscriptionId === source;
    if (!bridges) break;
    interlopers.push(ordered[index]);
    index += 1;
  }
  return { source, sourceCards, interlopers, end: index };
}
