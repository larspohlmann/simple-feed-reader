import { Injectable, computed, inject } from '@angular/core';
import { Observable, catchError, map, of, tap } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { FeedDirectoryApi } from './feed-directory-api';
import { FeedDirectoryScope } from './feed-directory.models';

const EMPTY_SCOPE: FeedDirectoryScope = { countries: [], languages: [] };

@Injectable({ providedIn: 'root' })
export class FeedDirectoryScopeService {
  private readonly auth = inject(AuthService);
  private readonly api = inject(FeedDirectoryApi);

  readonly known = computed(() => this.auth.user() !== null);
  readonly scope = computed(() => this.auth.user()?.preferences.feedDirectoryScope ?? EMPTY_SCOPE);
  readonly hasScope = computed(
    () => this.scope().countries.length > 0 && this.scope().languages.length > 0,
  );

  save(scope: FeedDirectoryScope): Observable<boolean> {
    return this.api.saveScope(scope).pipe(
      tap((user) => this.auth.user.set(user)),
      map(() => true),
      catchError(() => of(false)),
    );
  }
}
