export interface EtaLabel {
  readonly key: string;
  readonly params: { count: number };
}

/** Turns a remaining-seconds estimate into a coarse, honestly-approximate
 *  Transloco key + count. Ceil-rounded and floored at 1 so it never promises
 *  sooner than reality and never reads "~0". No DatePipe — that always renders
 *  en-US here (no LOCALE_ID + runtime Transloco switching). */
export function formatEta(seconds: number): EtaLabel {
  const safeSeconds = Math.max(1, Math.ceil(seconds));
  if (safeSeconds < 60) {
    return { key: 'reader.eta.seconds', params: { count: safeSeconds } };
  }
  return { key: 'reader.eta.minutes', params: { count: Math.ceil(safeSeconds / 60) } };
}
