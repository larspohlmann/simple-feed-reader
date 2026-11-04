import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { API_BASE_URL } from '../../core/api';
import { retryingTransientGatewayErrors } from '../../core/http/retry-transient-gateway-errors';
import { EntriesPage } from '../models';
import { TrendingResponseDto, trendingPage } from './trending-page';
import { TrendingWindow } from './trending.models';
import { DEFAULT_TRENDING_WINDOW } from './trending-window';

/** Trending's own endpoints (#2017): a window's stories, and hiding one. */
@Injectable({ providedIn: 'root' })
export class TrendingApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  /** One window's stories as a list page; the API answers the day when no window is named. */
  page(window: TrendingWindow = DEFAULT_TRENDING_WINDOW): Observable<EntriesPage> {
    return this.http
      .get<TrendingResponseDto>(`${this.base}/api/trending`, {
        params: new HttpParams().set('window', window),
        context: retryingTransientGatewayErrors(),
      })
      .pipe(map(trendingPage));
  }

  hideStory(window: TrendingWindow, entryId: number): Observable<void> {
    return this.http.post<void>(`${this.base}/api/trending/hidden`, { window, entryId });
  }
}
