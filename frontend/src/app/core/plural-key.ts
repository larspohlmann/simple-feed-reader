// src/app/core/plural-key.ts

/**
 * Picks between `${base}One` and `${base}Other`, the key-pair convention this
 * app uses in place of a Transloco pluralization plugin (see
 * `bulk-tag-dialog.component.ts`'s `addTagTitleOne`/`addTagTitleOther` and
 * `recommendation-run-history-month.component.ts`'s
 * `historyMonthSummaryOne`/`historyMonthSummaryOther`).
 *
 * Every caller must ship both `${base}One` and `${base}Other` in EVERY
 * language dictionary — there is no runtime fallback for a missing half of
 * the pair.
 */
export function pluralKey(base: string, count: number): string {
  return `${base}${count === 1 ? 'One' : 'Other'}`;
}
