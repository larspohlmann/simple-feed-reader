// src/app/core/transloco-loader.ts
import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Translation, TranslocoLoader } from '@jsverse/transloco';
import { Observable, of } from 'rxjs';
import { buildVersion } from '../../environments/version';
import { FALLBACK_LANG } from './language';
import englishDictionary from '../../../public/i18n/en.json';

/**
 * Dictionaries compiled into the build, served without touching the network.
 *
 * English is here because it is the fallback language, but the rule is
 * "bundled", not "fallback": add an entry and that language stops needing the
 * network too. Keyed loosely because Transloco hands the loader a plain string.
 */
const BUNDLED_DICTIONARIES: Readonly<Record<string, Translation>> = {
  [FALLBACK_LANG]: englishDictionary,
};

/** Loads a language's dictionary — bundled ones from the build, the rest from
 *  the statically-served `public/i18n/`.
 *
 *  THE FALLBACK LANGUAGE IS BUNDLED, not fetched: the preload used to gate
 *  boot on one uncached request, and a mobile browser resume-reloading on a
 *  reconnecting radio got a permanently blank page (#280). It must live HERE
 *  in the loader — Transloco's `load()` only consults its own request cache,
 *  so `setTranslation()` would not prevent the HTTP request.
 *
 *  The path is deliberately RELATIVE. The app is served at the domain root by
 *  the Docker setup and under a `/reader` subpath on Strato; a relative URL
 *  resolves against the document base URI, which `<base href>` sets per build,
 *  so one path is correct for both. A leading slash would 404 under a subpath.
 *
 *  The release version is deliberately in the QUERY STRING. The dictionaries
 *  sit at a path that never changes, so without it a cache may serve the
 *  previous release's copy and every key added since renders as its raw name
 *  (#141). Naming the version restores the same guarantee the hashed bundles
 *  have: a new release asks for a URL the cache has never held.
 */
@Injectable({ providedIn: 'root' })
export class HttpTranslocoLoader implements TranslocoLoader {
  private readonly http = inject(HttpClient);

  getTranslation(lang: string): Observable<Translation> {
    const bundled = BUNDLED_DICTIONARIES[lang];
    if (bundled) return of(bundled);

    return this.http.get<Translation>(`i18n/${lang}.json`, {
      params: { v: buildVersion.version },
    });
  }
}
