// src/app/reader/format.ts

/**
 * A short, localised "time ago" label (e.g. "5 min ago" / "vor 5 Min.") built from
 * Intl.RelativeTimeFormat so it follows the active UI language. `locale` is the
 * language tag ('en' | 'de'); pass a fixed `now` in tests.
 */
export function relativeTime(iso: string, locale = 'en', now: Date = new Date()): string {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return '';
  const s = Math.max(0, Math.floor((now.getTime() - then) / 1000));
  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto', style: 'short' });
  if (s < 60) return rtf.format(0, 'second'); // numeric:auto → "now" / "jetzt"
  const m = Math.floor(s / 60);
  if (m < 60) return rtf.format(-m, 'minute');
  const h = Math.floor(m / 60);
  if (h < 24) return rtf.format(-h, 'hour');
  return rtf.format(-Math.floor(h / 24), 'day');
}

/** A localised long date (e.g. "July 22, 2026" / "22. Juli 2026"). */
export function formatLongDate(iso: string, locale = 'en'): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return new Intl.DateTimeFormat(locale, { dateStyle: 'long' }).format(d);
}

/**
 * A localised long date, or an explicit fallback when there is no date at
 * all -- the one idiom behind every "date, or 'never'" field on the admin
 * screens (a login, a refresh, an approval). `fallback` is an
 * already-translated string, not a key: this module has no Transloco
 * dependency, so the caller resolves the key and passes the result in.
 */
export function formatDateOr(iso: string | null, locale: string, fallback: string): string {
  return iso ? formatLongDate(iso, locale) : fallback;
}

/**
 * Whether a trial's end date has already passed. An account with no trial at
 * all (`iso === null`) is never "expired" -- that only describes one that ran
 * out of time. Shared by the sidebar indicator and both admin screens so the
 * "past" boundary (`<=`) cannot drift between them.
 */
export function trialExpired(iso: string | null, now: number = Date.now()): boolean {
  return iso !== null && new Date(iso).getTime() <= now;
}

/**
 * Whole days left in a still-running trial, or null when there is no trial or
 * it has already ended -- the one "how much runway is left" figure every
 * trial indicator in the app is built from.
 */
export function trialDaysRemaining(iso: string | null, now: number = Date.now()): number | null {
  if (iso === null) return null;
  const remainingMs = new Date(iso).getTime() - now;
  return remainingMs > 0 ? Math.ceil(remainingMs / 86_400_000) : null;
}
