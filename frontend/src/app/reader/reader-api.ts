// src/app/reader/reader-api.ts
import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import {
  EntriesPage,
  EntryQuery,
  EntryStatePatch,
  MarkReadScope,
  RefreshReport,
  SubscribeResult,
  SubscriptionDto,
  EntryStateDto,
} from './models';

@Injectable({ providedIn: 'root' })
export class ReaderApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  subscriptions(): Observable<{ subscriptions: SubscriptionDto[] }> {
    return this.http.get<{ subscriptions: SubscriptionDto[] }>(`${this.base}/api/subscriptions`);
  }

  subscribe(url: string): Observable<SubscribeResult> {
    return this.http.post<SubscribeResult>(`${this.base}/api/subscriptions`, { url });
  }

  entries(query: EntryQuery, cursor?: string | null): Observable<EntriesPage> {
    let params = new HttpParams().set('view', query.view);
    if (query.subscription != null) params = params.set('subscription', query.subscription);
    if (query.tag != null) params = params.set('tag', query.tag);
    if (cursor) params = params.set('cursor', cursor);
    return this.http.get<EntriesPage>(`${this.base}/api/entries`, { params });
  }

  updateState(id: number, patch: EntryStatePatch): Observable<{ state: EntryStateDto }> {
    return this.http.patch<{ state: EntryStateDto }>(`${this.base}/api/entries/${id}/state`, patch);
  }

  markRead(scope: MarkReadScope, until: string, id?: number): Observable<void> {
    const body: Record<string, unknown> = { scope, until };
    if (id != null) body['id'] = id;
    return this.http.post<void>(`${this.base}/api/entries/mark-read`, body);
  }

  refresh(): Observable<RefreshReport> {
    return this.http.post<RefreshReport>(`${this.base}/api/refresh`, {});
  }
}
