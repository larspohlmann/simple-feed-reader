/** The signed magnitude and unit `relativeTime`/`relativeTimeNarrow` share —
 *  which bucket (second/minute/hour/day) an instant falls into is one
 *  decision; how wide to render it is a second, independent one. */
function relativeMagnitude(
  iso: string,
  now: Date,
): { value: number; unit: Intl.RelativeTimeFormatUnit } | null {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return null;
  const s = Math.max(0, Math.floor((now.getTime() - then) / 1000));
  if (s < 60) return { value: 0, unit: 'second' }; // numeric:auto → "now" / "jetzt"
  const m = Math.floor(s / 60);
  if (m < 60) return { value: -m, unit: 'minute' };
  const h = Math.floor(m / 60);
  if (h < 24) return { value: -h, unit: 'hour' };
  return { value: -Math.floor(h / 24), unit: 'day' };
}

/** One formatter per locale and style, not one per row: constructing an
 *  `Intl.RelativeTimeFormat` resolves locale data, and every card in the
 *  magazine builds two labels. */
const relativeFormatters = new Map<string, Intl.RelativeTimeFormat>();

function relativeFormatter(locale: string, style: 'short' | 'narrow'): Intl.RelativeTimeFormat {
  const key = `${locale}:${style}`;
  const cached = relativeFormatters.get(key);
  if (cached) return cached;
  const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'auto', style });
  relativeFormatters.set(key, formatter);
  return formatter;
}

/**
 * A short, localised "time ago" label (e.g. "5 min ago" / "vor 5 Min.") built from
 * Intl.RelativeTimeFormat so it follows the active UI language. `locale` is the
 * language tag ('en' | 'de'); pass a fixed `now` in tests.
 */
export function relativeTime(iso: string, locale = 'en', now: Date = new Date()): string {
  const magnitude = relativeMagnitude(iso, now);
  if (!magnitude) return '';
  return relativeFormatter(locale, 'short').format(magnitude.value, magnitude.unit);
}

/**
 * The narrowest form `relativeTime` has (e.g. "5m ago" / "vor 5 m") — same
 * bucketing, `style: 'narrow'` instead of `'short'`, for a kicker line too
 * tight for the full label (#769). Never longer than `relativeTime`'s output.
 */
export function relativeTimeNarrow(iso: string, locale = 'en', now: Date = new Date()): string {
  const magnitude = relativeMagnitude(iso, now);
  if (!magnitude) return '';
  return relativeFormatter(locale, 'narrow').format(magnitude.value, magnitude.unit);
}

/** A localised long date (e.g. "July 22, 2026" / "22. Juli 2026"). */
export function formatLongDate(iso: string, locale = 'en'): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return new Intl.DateTimeFormat(locale, { dateStyle: 'long' }).format(d);
}

/**
 * A localised long date, or an explicit fallback for "no date" fields (login,
 * refresh, approval). `fallback` is an already-translated string, not a key —
 * this module has no Transloco dependency, so the caller resolves it first.
 */
export function formatDateOr(iso: string | null, locale: string, fallback: string): string {
  return iso ? formatLongDate(iso, locale) : fallback;
}

/**
 * A short localised day-in-month (e.g. "Aug 16" / "16. Aug") for a row inside a
 * month-headed section, where `formatLongDate`'s year and full month are noise.
 * The month stays (short, not dropped) so a screenshotted row still reads as a date.
 */
export function formatDayInMonth(iso: string, locale = 'en'): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' }).format(d);
}

/**
 * A zero-padded 24-hour `HH:MM` clock time, browser timezone. `hourCycle: 'h23'`
 * is forced regardless of locale — the debug log reads as a timeline
 * (`start → end`), and 12-hour AM/PM would make that arrow misleading.
 */
export function formatTime(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(d);
}

/**
 * Whether a trial's end date has passed. No trial (`iso === null`) is never
 * "expired" — that only describes one that ran out of time. Shared by the sidebar
 * indicator and both admin screens so the `<=` boundary cannot drift between them.
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

/**
 * Bytes as a whole KB figure, floored at 1 so a nonzero count never shows "0 KB".
 * Shared by the #309 debug log and the for-you bar's liveness figure so the
 * rounding can't drift between the two copies.
 */
export function bytesToKb(bytes: number): number {
  return Math.max(1, Math.round(bytes / 1024));
}

/**
 * A whole number with the active language's grouping separators -- `8,192` in
 * English, `8.192` in German. Uses `Intl` (not the fixed `LOCALE_ID`) for the same
 * reason `formatCost` does: a raw number beside `22. Juli 2026` would mix locales.
 */
export function formatInteger(value: number, locale: string): string {
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(value);
}

/** Nano-credits per credit. The API stores money as an integer -- floats do
 *  not hold money -- and this is the one place it becomes a human figure. */
const NANO_PER_CREDIT = 1_000_000_000;

/** How many decimals a price reads at. Four, not the provider's five: the fifth
 *  decimal cost the run-history row its phone width (#465), and no run this card
 *  records is cheap enough to round to `$ 0.0000` at four decimals anyway. */
const COST_FRACTION_DIGITS = 4;

/** What no reported price renders as. The provider said nothing about cost (a
 *  local model, or a run older than the column), which is a different
 *  statement from a cost of zero -- so it must not render as one, and it must
 *  not carry a currency symbol either. */
const NO_PRICE = '—';

/**
 * A price in nano-credits at card precision (`$ 0.0014`). Symbol always leads;
 * the number goes through `Intl` (not `toFixed`, which always writes `.`) so a
 * German card reads `$ 0,0014` beside `22. Juli 2026`. Shared by the history
 * card and each month section so the rounding can't drift between copies.
 */
export function formatCost(nanoCredits: number | null, locale: string): string {
  if (nanoCredits === null) return NO_PRICE;
  const credits = new Intl.NumberFormat(locale, {
    minimumFractionDigits: COST_FRACTION_DIGITS,
    maximumFractionDigits: COST_FRACTION_DIGITS,
  }).format(nanoCredits / NANO_PER_CREDIT);
  return `$ ${credits}`;
}

/**
 * A duration as `m:ss` -- `0:47`, `2:07`, `62:03`. Deliberately not translated:
 * a padded `m:ss` reads as a duration and keeps the column aligned, unlike a
 * value that switches between `47 s` and `2 min 7 s`. No hours field: a run is
 * bounded by a 600 s per-call timeout, so it would always read zero. Clamped at
 * zero since the server never reports a negative duration.
 */
export function formatDuration(seconds: number): string {
  const total = Math.max(0, Math.round(seconds));
  const minutes = Math.floor(total / 60);
  return `${minutes}:${String(total % 60).padStart(2, '0')}`;
}
