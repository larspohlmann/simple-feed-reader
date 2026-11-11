import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { CurrentUser } from '../../core/auth/auth.service';
import {
  FeedDirectoryCountryFile,
  FeedDirectoryIndex,
  FeedDirectoryScope,
  FeedDirectorySubscribed,
} from './feed-directory.models';

@Injectable({ providedIn: 'root' })
export class FeedDirectoryApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  index(): Observable<FeedDirectoryIndex> {
    return this.http.get<FeedDirectoryIndex>(`${this.base}/api/feed-directory/index`);
  }

  country(code: string): Observable<FeedDirectoryCountryFile> {
    return this.http.get<FeedDirectoryCountryFile>(
      `${this.base}/api/feed-directory/countries/${encodeURIComponent(code)}`,
    );
  }

  subscribed(): Observable<FeedDirectorySubscribed> {
    return this.http.get<FeedDirectorySubscribed>(`${this.base}/api/feed-directory/subscribed`);
  }

  saveScope(scope: FeedDirectoryScope): Observable<CurrentUser> {
    return this.http.patch<CurrentUser>(`${this.base}/api/me/feed-directory-scope`, scope);
  }
}
