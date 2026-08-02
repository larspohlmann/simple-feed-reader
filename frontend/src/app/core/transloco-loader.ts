// src/app/core/transloco-loader.ts
import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Translation, TranslocoLoader } from '@jsverse/transloco';
import { buildVersion } from '../../environments/version';

/** Loads a language's dictionary from the statically-served `public/i18n/`.
 *
 *  The path is deliberately RELATIVE. The app is served at the domain root by
 *  the Docker setup and under a `/reader` subpath on Strato; a relative URL
 *  resolves against the document base URI, which `<base href>` sets per build,
 *  so one path is correct for both. A leading slash would 404 under a subpath.
 *
 *  The release version is deliberately in the QUERY STRING. Every other asset
 *  the SPA loads is content-hashed, so a deploy renames it and no cache can
 *  answer for it; the dictionaries sit at a path that never changes and go out
 *  with no Cache-Control header at all, so the browser falls back to heuristic
 *  freshness and may serve the previous release's copy. Every key added since
 *  then renders as its raw name -- in the new features, where it looks most
 *  broken (#141). Naming the version restores the same guarantee the hashed
 *  bundles have: a new release asks for a URL the cache has never held.
 */
@Injectable({ providedIn: 'root' })
export class HttpTranslocoLoader implements TranslocoLoader {
  private readonly http = inject(HttpClient);

  getTranslation(lang: string) {
    return this.http.get<Translation>(`i18n/${lang}.json`, {
      params: { v: buildVersion.version },
    });
  }
}
