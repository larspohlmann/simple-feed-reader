// src/app/reader/reader-api.ts
import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import {
  EntriesPage,
  EntryDto,
  EntryQuery,
  EntryStatePatch,
  FeedPreview,
  MarkReadScope,
  OpmlImportResult,
  ReaderContent,
  RefreshReport,
  SubscribeResult,
  SubscriptionDto,
  SubscriptionUpdate,
  EntryStateDto,
  TagDto,
  TagInput,
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

  /** A single entry by id — lets a deep link open an entry not in the loaded page. */
  entry(id: number): Observable<{ entry: EntryDto }> {
    return this.http.get<{ entry: EntryDto }>(`${this.base}/api/entries/${id}`);
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

  readerContent(entryId: number): Observable<ReaderContent> {
    return this.http.get<ReaderContent>(`${this.base}/api/entries/${entryId}/reader`);
  }

  refresh(): Observable<RefreshReport> {
    return this.http.post<RefreshReport>(`${this.base}/api/refresh`, {});
  }

  updateSubscription(
    id: number,
    body: SubscriptionUpdate,
  ): Observable<{ subscription: SubscriptionDto }> {
    return this.http.patch<{ subscription: SubscriptionDto }>(
      `${this.base}/api/subscriptions/${id}`,
      body,
    );
  }

  deleteSubscription(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/subscriptions/${id}`);
  }

  /** Persist the untagged "Feeds" order. */
  reorderSubscriptions(subscriptionIds: number[]): Observable<void> {
    return this.http.patch<void>(`${this.base}/api/subscriptions/reorder`, { subscriptionIds });
  }

  tags(): Observable<{ tags: TagDto[] }> {
    return this.http.get<{ tags: TagDto[] }>(`${this.base}/api/tags`);
  }

  createTag(body: TagInput): Observable<{ tag: TagDto }> {
    return this.http.post<{ tag: TagDto }>(`${this.base}/api/tags`, body);
  }

  updateTag(id: number, body: TagInput): Observable<{ tag: TagDto }> {
    return this.http.patch<{ tag: TagDto }>(`${this.base}/api/tags/${id}`, body);
  }

  deleteTag(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/tags/${id}`);
  }

  /** Persist the sidebar tag order (the full tag id list, in order). */
  reorderTags(tagIds: number[]): Observable<{ tags: TagDto[] }> {
    return this.http.patch<{ tags: TagDto[] }>(`${this.base}/api/tags/reorder`, { tagIds });
  }

  /** Persist the order of feeds within one tag. */
  setTagFeedOrder(tagId: number, subscriptionIds: number[]): Observable<void> {
    return this.http.patch<void>(`${this.base}/api/tags/${tagId}/feed-order`, { subscriptionIds });
  }

  exportOpml(): Observable<string> {
    return this.http.get(`${this.base}/api/opml/export`, { responseType: 'text' });
  }

  importOpml(xml: string): Observable<OpmlImportResult> {
    return this.http.post<OpmlImportResult>(`${this.base}/api/opml/import`, xml, {
      headers: { 'Content-Type': 'text/xml' },
    });
  }

  /** Preview a candidate feed's contents before subscribing. */
  previewFeed(url: string): Observable<{ feed: FeedPreview }> {
    return this.http.post<{ feed: FeedPreview }>(`${this.base}/api/feeds/preview`, { url });
  }
}
