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

/** Nano-credits per credit. The API stores money as an integer -- floats do
 *  not hold money -- and this is the one place it becomes a human figure. */
const NANO_PER_CREDIT = 1_000_000_000;

/** How many decimals a price is worth reading at. Five is what the provider's
 *  own logs show, and it is fine enough that a single cheap run does not
 *  collapse to zero. */
const COST_FRACTION_DIGITS = 5;

/** What no reported price renders as. The provider said nothing about cost (a
 *  local model, or a run older than the column), which is a different
 *  statement from a cost of zero -- so it must not render as one, and it must
 *  not carry a currency symbol either. */
const NO_PRICE = '—';

/**
 * A price in nano-credits as the provider's own logs write it: `$ 0.00137`.
 *
 * The symbol always leads, the way the provider renders it. The number does
 * not: it goes through `Intl` on the active UI language, because `toFixed`
 * always writes a `.` and a German card showing `22. Juli 2026` beside
 * `0.00137` is two locales in one line. So German reads `$ 0,00137`.
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
