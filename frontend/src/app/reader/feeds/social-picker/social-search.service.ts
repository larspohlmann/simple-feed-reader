import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { SubscriptionDto } from '../../models';

export interface SearchPreviewPost {
  readonly url: string | null;
  readonly author: string | null;
  readonly authorAvatarUrl: string | null;
  readonly text: string;
  readonly publishedAt: string | null;
}

export interface SearchPreview {
  readonly count: number;
  readonly posts: readonly SearchPreviewPost[];
}

@Injectable({ providedIn: 'root' })
export class SocialSearchService {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  preview(accountId: number, query: string): Observable<SearchPreview> {
    return this.http.get<SearchPreview>(
      `${this.base}/api/me/social-accounts/${accountId}/search-preview`,
      { params: { query } },
    );
  }

  changeQuery(
    subscriptionId: number,
    query: string,
  ): Observable<{ subscription: SubscriptionDto }> {
    return this.http.put<{ subscription: SubscriptionDto }>(
      `${this.base}/api/subscriptions/${subscriptionId}/search-query`,
      { query },
    );
  }
}
