import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map, tap } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { retryingTransientGatewayErrors } from '../../core/http/retry-transient-gateway-errors';
import { ListPage } from '../state/list-page';
import { TrendingBuiltAt } from './trending-built-at';
import { TrendingResponseDto, trendingPage } from './trending-page';
import { FollowedStoriesResponseDto, TrendingWindow } from './trending.models';
import { DEFAULT_TRENDING_WINDOW } from './trending-window';

/** Trending's own endpoints (#2017): a window's stories, hiding one, and following one (#2128). */
@Injectable({ providedIn: 'root' })
export class TrendingApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);
  private readonly builtAt = inject(TrendingBuiltAt);

  /** One window's stories as a list page; the API answers the day when no window is named. */
  page(window: TrendingWindow = DEFAULT_TRENDING_WINDOW): Observable<ListPage> {
    return this.http
      .get<TrendingResponseDto>(`${this.base}/api/trending`, {
        params: new HttpParams().set('window', window),
        context: retryingTransientGatewayErrors(),
      })
      .pipe(
        tap((response) => this.builtAt.record(window, response.builtAt)),
        map(trendingPage),
      );
  }

  hideStory(storyId: number): Observable<void> {
    return this.http.post<void>(`${this.base}/api/trending/hidden`, { storyId });
  }

  follow(storyId: number): Observable<void> {
    return this.http.put<void>(this.followUrl(storyId), null);
  }

  unfollow(storyId: number): Observable<void> {
    return this.http.delete<void>(this.followUrl(storyId));
  }

  followed(): Observable<FollowedStoriesResponseDto> {
    return this.http.get<FollowedStoriesResponseDto>(`${this.base}/api/trending/followed`);
  }

  /** Starts the story's "new since you last opened it" count afresh. */
  opened(storyId: number): Observable<void> {
    return this.http.post<void>(`${this.base}/api/trending/stories/${storyId}/opened`, null);
  }

  private followUrl(storyId: number): string {
    return `${this.base}/api/trending/stories/${storyId}/follow`;
  }
}
