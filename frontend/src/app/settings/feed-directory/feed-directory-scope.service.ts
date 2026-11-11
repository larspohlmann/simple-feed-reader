import { Injectable, computed, inject } from '@angular/core';
import { Observable, catchError, map, of, tap } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { FeedDirectoryScope } from '../../core/preferences/feed-directory-scope';
import { FeedDirectoryApi } from './feed-directory-api';

export type FeedDirectoryAccountState = 'loading' | 'failed' | 'ready';

const EMPTY_SCOPE: FeedDirectoryScope = { countries: [], languages: [] };

@Injectable({ providedIn: 'root' })
export class FeedDirectoryScopeService {
  private readonly auth = inject(AuthService);
  private readonly api = inject(FeedDirectoryApi);

  readonly accountState = computed<FeedDirectoryAccountState>(() => {
    if (this.auth.user() !== null) return 'ready';
    return this.auth.accountResolved() ? 'failed' : 'loading';
  });
  readonly scope = computed(() => this.auth.user()?.preferences.feedDirectoryScope ?? EMPTY_SCOPE);
  readonly hasScope = computed(
    () => this.scope().countries.length > 0 && this.scope().languages.length > 0,
  );

  retryAccount(): void {
    this.auth.ensureUserLoaded().subscribe({ error: () => undefined });
  }

  save(scope: FeedDirectoryScope): Observable<boolean> {
    return this.api.saveScope(scope).pipe(
      tap((user) => this.auth.adopt(user)),
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
