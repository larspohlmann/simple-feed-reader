// src/app/reader/format.ts

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
 * bucketing, `Intl.RelativeTimeFormat`'s `style: 'narrow'` instead of
 * `'short'`. For a kicker line whose card is too tight for the full label
 * (#769); never longer than `relativeTime`'s own output, though German's
 * narrow and short forms coincide for some units.
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
 * A short localised day-in-month (e.g. "Aug 16" / "16. Aug"). For a row that
 * already sits inside a month-headed section, the year and full month name
 * `formatLongDate` renders are noise -- but the month stays, short rather
 * than dropped, so a row screenshotted out of its section still reads as a
 * date on its own.
 */
export function formatDayInMonth(iso: string, locale = 'en'): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '';
  return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' }).format(d);
}

/**
 * A zero-padded 24-hour `HH:MM` clock time in the browser's own timezone.
 * `hourCycle: 'h23'` is forced regardless of locale -- the debug log's run
 * summary and row times read as a timeline (`start → end`), and a 12-hour
 * AM/PM rendering would make that arrow misleading rather than helpful.
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

/**
 * Bytes as a whole KB figure, floored at 1 so a nonzero byte count never
 * displays as "0 KB". Shared by the #309 debug log's per-entry sizes and the
 * for-you bar's in-flight liveness figure -- both round the same raw byte
 * count for a human, and a copy in each place would drift.
 */
export function bytesToKb(bytes: number): number {
  return Math.max(1, Math.round(bytes / 1024));
}

/**
 * A whole number with the active language's grouping separators -- `8,192` in
 * English, `8.192` in German. Goes through `Intl` on the active UI language for
 * the same reason `formatCost` does: `LOCALE_ID` is fixed here, so a raw number
 * beside a `22. Juli 2026` would be two locales in one line.
 */
export function formatInteger(value: number, locale: string): string {
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 0 }).format(value);
}

/** Nano-credits per credit. The API stores money as an integer -- floats do
 *  not hold money -- and this is the one place it becomes a human figure. */
const NANO_PER_CREDIT = 1_000_000_000;

/** How many decimals a price is worth reading at. Four, not the five the
 *  provider's own logs show: the fifth decimal is a figure nobody reads, and
 *  it cost the run-history row on a phone the width its other five columns
 *  needed (#465). A run cheap enough to round to `$ 0.0000` at four decimals
 *  would have to cost under a ten-thousandth of a credit, which no run this
 *  card has ever recorded comes near. */
const COST_FRACTION_DIGITS = 4;

/** What no reported price renders as. The provider said nothing about cost (a
 *  local model, or a run older than the column), which is a different
 *  statement from a cost of zero -- so it must not render as one, and it must
 *  not carry a currency symbol either. */
const NO_PRICE = '—';

/**
 * A price in nano-credits, at the precision this card reads at: `$ 0.0014`.
 *
 * The symbol always leads, the way the provider renders it. The number does
 * not: it goes through `Intl` on the active UI language, because `toFixed`
 * always writes a `.` and a German card showing `22. Juli 2026` beside
 * `0.0014` is two locales in one line. So German reads `$ 0,0014`.
 *
 * Shared rather than owned by the history card: the card renders the account
 * total and each month section renders its own, and a second copy of the
 * rounding would drift.
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
 * A duration as `m:ss` -- `0:47`, `2:07`, `62:03`.
 *
 * Deliberately not translated. The seconds-only rendering this replaces
 * needed a dictionary key in every language just to carry the letter `s`,
 * where a padded `m:ss` reads as a duration on its own -- and it keeps the
 * column aligned, which a value that switches between `47 s` and `2 min 7 s`
 * cannot.
 *
 * Minutes do not roll into hours. A recommendation run is bounded by a 600 s
 * per-call timeout over a handful of calls, so an hours field would be a
 * column that is always zero.
 *
 * Clamped at zero: the server already refuses to report a negative duration,
 * and this must not be the place that starts.
 */
export function formatDuration(seconds: number): string {
  const total = Math.max(0, Math.round(seconds));
  const minutes = Math.floor(total / 60);
  return `${minutes}:${String(total % 60).padStart(2, '0')}`;
}
