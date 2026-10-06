import { formatDayInMonth, formatTime } from '../../reader/format';

/** Date and clock time together, e.g. "21 Aug 22:54": the debug log spans
 *  several days of runs, so the day is shown beside every time (#541). */
export function debugLogTime(iso: string, lang: string): string {
  return `${formatDayInMonth(iso, lang)} ${formatTime(iso)}`;
}
