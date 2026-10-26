/** A scrubber's track as fractions of its length: played, then cached ahead of the playhead (#1429). */
export interface ScrubFill {
  played: number;
  cached: number;
}

export function scrubFill(position: number, buffered: number, duration: number): ScrubFill {
  const fraction = (seconds: number): number =>
    duration > 0 ? Math.min(1, Math.max(0, seconds / duration)) : 0;
  return { played: fraction(position), cached: fraction(buffered) };
}
