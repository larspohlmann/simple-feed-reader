/** The chapter playing at `seconds`, as an index into ascending `starts`; -1 before the first or with no position. */
export function currentChapterIndex(starts: readonly number[], seconds: number | null): number {
  if (seconds === null) return -1;
  let low = 0;
  let high = starts.length - 1;
  let found = -1;
  while (low <= high) {
    const middle = (low + high) >> 1;
    if (starts[middle] <= seconds) {
      found = middle;
      low = middle + 1;
    } else {
      high = middle - 1;
    }
  }
  return found;
}
