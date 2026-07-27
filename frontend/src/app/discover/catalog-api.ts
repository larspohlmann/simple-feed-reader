// src/app/discover/catalog-api.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { CatalogCategoryDto, CatalogFeedDto, OnboardingSubscribeResult } from './catalog.models';

@Injectable({ providedIn: 'root' })
export class CatalogApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  load(): Observable<{ categories: CatalogCategoryDto[] }> {
    return this.http
      .get<{ categories: CatalogCategoryDto[] }>(`${this.base}/api/catalog`)
      .pipe(map((catalog) => ({ categories: catalog.categories.map(this.withResolvedFavicons) })));
  }

  /**
   * Favicon URLs arrive from the server as bare API paths, because an `<img>`
   * cannot go through HttpClient and the server does not know where the app is
   * mounted. Joining them with the API base is therefore this layer's job, the
   * same as for every URL it builds itself — and it is not cosmetic: under
   * `/reader` an unjoined path resolves against the apex domain and 404s, so
   * the whole picker renders with broken images (#144).
   */
  private readonly withResolvedFavicons = (category: CatalogCategoryDto): CatalogCategoryDto => ({
    ...category,
    feeds: category.feeds.map((feed): CatalogFeedDto => ({
      ...feed,
      faviconUrl: `${this.base}${feed.faviconUrl}`,
    })),
  });

  subscribe(catalogFeedIds: number[]): Observable<OnboardingSubscribeResult> {
    return this.http.post<OnboardingSubscribeResult>(`${this.base}/api/onboarding/subscribe`, {
      catalogFeedIds,
    });
  }
}
