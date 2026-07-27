// src/app/discover/catalog-api.ts
import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { CatalogCategoryDto, OnboardingSubscribeResult } from './catalog.models';

@Injectable({ providedIn: 'root' })
export class CatalogApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  load(): Observable<{ categories: CatalogCategoryDto[] }> {
    return this.http.get<{ categories: CatalogCategoryDto[] }>(`${this.base}/api/catalog`);
  }

  subscribe(catalogFeedIds: number[]): Observable<OnboardingSubscribeResult> {
    return this.http.post<OnboardingSubscribeResult>(`${this.base}/api/onboarding/subscribe`, {
      catalogFeedIds,
    });
  }
}
