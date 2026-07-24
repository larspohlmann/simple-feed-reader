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
