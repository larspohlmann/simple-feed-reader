// src/app/core/boot-language.ts
import { TranslocoService } from '@jsverse/transloco';
import { catchError, firstValueFrom, timeout } from 'rxjs';
import { FALLBACK_LANG, Lang } from './language';

/**
 * How long boot waits for a network-loaded dictionary before falling back to
 * the bundled one. Long enough for a slow mobile round trip, short enough
 * that a resume-reload on a still-reconnecting radio shows English instead of
 * nothing (#280).
 */
export const DICTIONARY_WAIT_MS = 3000;

/**
 * Resolve the initial dictionary before the first render, without ever
 * holding the render hostage. The happy path keeps the original guarantee —
 * a German account does not flash English (#141's initializer) — but failure
 * and stall now land on the bundled fallback language instead of rejecting
 * `bootstrapApplication`, which left `<app-root>` permanently empty.
 *
 * The final catch is defense in depth: the fallback load is network-free
 * (see transloco-loader.ts) and should not fail, but a blank page is the one
 * outcome this function exists to make impossible.
 */
export function preloadInitialLanguage(transloco: TranslocoService, lang: Lang): Promise<unknown> {
  return firstValueFrom(
    transloco.load(lang).pipe(
      timeout(DICTIONARY_WAIT_MS),
      catchError(() => {
        transloco.setActiveLang(FALLBACK_LANG);
        return transloco.load(FALLBACK_LANG);
      }),
    ),
  ).catch(() => undefined);
}
