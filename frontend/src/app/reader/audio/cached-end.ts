/** The end of the cached range `playhead` sits in; a range elsewhere (after a seek) is not ahead of it. */
export function cachedEnd(ranges: TimeRanges, playhead: number): number {
  for (let index = 0; index < ranges.length; index++) {
    if (ranges.start(index) <= playhead && playhead <= ranges.end(index)) return ranges.end(index);
  }
  return playhead;
}
