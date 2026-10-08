/** A scrubber's track as percentages: played, then cached ahead of the playhead (#1429). */
export interface ScrubFill {
  played: string;
  cached: string;
}

export function scrubFill(position: number, buffered: number, duration: number): ScrubFill {
  const percent = (seconds: number): string =>
    `${duration > 0 ? Math.min(100, (seconds / duration) * 100) : 0}%`;
  return { played: percent(position), cached: percent(buffered) };
}
