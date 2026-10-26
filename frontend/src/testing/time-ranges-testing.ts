/** A media element's `buffered` ranges from `[start, end]` pairs. */
export function timeRanges(...spans: [number, number][]): TimeRanges {
  return {
    length: spans.length,
    start: (index: number) => spans[index][0],
    end: (index: number) => spans[index][1],
  };
}
