// src/app/reader/reader-api.ts
import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { PAGE_SIZE } from './paging';
import { RefreshScope } from './query';
import {
  DebugLogDetail,
  DebugLogPayload,
  EntriesPage,
  EntryDto,
  EntryQuery,
  EntryStatePatch,
  FeedPreview,
  MarkReadScope,
  OpmlImportResult,
  ReaderContent,
  RecommendationRunReport,
  RefreshReport,
  RunHistoryMonthPage,
  RunHistoryOverview,
  SubscribeResult,
  SubscriptionDto,
  SubscriptionsResponse,
  SubscriptionUpdate,
  EntryStateDto,
  TagDto,
  TagInput,
} from './models';

@Injectable({ providedIn: 'root' })
export class ReaderApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  subscriptions(): Observable<SubscriptionsResponse> {
    return this.http.get<SubscriptionsResponse>(`${this.base}/api/subscriptions`);
  }

  subscribe(url: string, format?: string, tagIds?: number[]): Observable<SubscribeResult> {
    const body: { url: string; format?: string; tagIds?: number[] } = { url };
    if (format) body.format = format;
    // Omit an empty selection so the body stays byte-compatible with clients
    // (and tests) that never send tags.
    if (tagIds && tagIds.length > 0) body.tagIds = tagIds;
    return this.http.post<SubscribeResult>(`${this.base}/api/subscriptions`, body);
  }

  /** A single entry by id — lets a deep link open an entry not in the loaded page. */
  entry(id: number): Observable<{ entry: EntryDto }> {
    return this.http.get<{ entry: EntryDto }>(`${this.base}/api/entries/${id}`);
  }

  entries(query: EntryQuery, cursor?: string | null): Observable<EntriesPage> {
    if (query.q) return this.searchEntries(query.q, cursor);
    let params = new HttpParams().set('view', query.view).set('limit', PAGE_SIZE);
    if (query.subscription != null) params = params.set('subscription', query.subscription);
    if (query.tag != null) params = params.set('tag', query.tag);
    if (cursor) params = params.set('cursor', cursor);
    return this.http.get<EntriesPage>(`${this.base}/api/entries`, { params });
  }

  /** Search carries none of the list's filters — it is its own view over every
   *  subscription — so it never forwards `view`, `tag` or `subscription`. */
  private searchEntries(term: string, cursor?: string | null): Observable<EntriesPage> {
    let params = new HttpParams().set('q', term).set('limit', PAGE_SIZE);
    if (cursor) params = params.set('cursor', cursor);
    return this.http.get<EntriesPage>(`${this.base}/api/entries/search`, { params });
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

  /** Omit the scope (or pass an empty one) to refresh all the caller's due
   *  feeds; scope by feedId for a single feed (e.g. a just-added one) or by
   *  tagId for every feed carrying that tag. */
  refresh(scope?: RefreshScope): Observable<RefreshReport> {
    let params = new HttpParams();
    if (scope?.feedId != null) params = params.set('feedId', scope.feedId);
    else if (scope?.tagId != null) params = params.set('tag', scope.tagId);
    return this.http.post<RefreshReport>(`${this.base}/api/refresh`, {}, { params });
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
  previewFeed(url: string, format?: string): Observable<{ feed: FeedPreview }> {
    return this.http.post<{ feed: FeedPreview }>(
      `${this.base}/api/feeds/preview`,
      format ? { url, format } : { url },
    );
  }

  /** Start a new for-you recommendation run. */
  startRecommendations(): Observable<RecommendationRunReport> {
    return this.http.post<RecommendationRunReport>(`${this.base}/api/recommendations/runs`, {});
  }

  /** Resume the latest failed run at the batch that failed. 409s when there is
   *  no failed run to resume. */
  resumeRecommendations(): Observable<RecommendationRunReport> {
    return this.http.post<RecommendationRunReport>(
      `${this.base}/api/recommendations/runs/resume`,
      {},
    );
  }

  /** Advance the in-flight recommendation run by one batch. */
  tickRecommendations(): Observable<RecommendationRunReport> {
    return this.http.post<RecommendationRunReport>(
      `${this.base}/api/recommendations/runs/tick`,
      {},
    );
  }

  /** Stops the in-flight run at the user's request. Refuses with a 409 when
   *  nothing is running. This does not cancel the provider call already in
   *  flight -- that spend is committed -- it stops every call after it. */
  stopRecommendations(): Observable<RecommendationRunReport> {
    return this.http.post<RecommendationRunReport>(
      `${this.base}/api/recommendations/runs/stop`,
      {},
    );
  }

  /** The recommendation run in flight, if any -- used to resume a poll loop on boot. */
  currentRecommendations(): Observable<RecommendationRunReport> {
    return this.http.get<RecommendationRunReport>(`${this.base}/api/recommendations/runs/current`);
  }

  /** The provider calls logged for one for-you run, in call order, plus that
   *  run's own summary and the retained runs the panel may switch to. Without
   *  a runId the newest run answers -- null when the user has never run. */
  debugLog(runId?: number): Observable<DebugLogPayload> {
    const query = runId === undefined ? '' : `?run=${runId}`;
    return this.http.get<DebugLogPayload>(
      `${this.base}/api/recommendations/runs/debug-log${query}`,
    );
  }

  /** The full request/response body for one logged provider call. */
  debugLogEntry(id: number): Observable<DebugLogDetail> {
    return this.http.get<DebugLogDetail>(`${this.base}/api/recommendations/runs/debug-log/${id}`);
  }

  /** Every month this account has run in, with that month's own run count and
   *  spend, plus the newest month's first page and the all-time total. One
   *  call, because the card's first paint needs all of it and each request
   *  costs a PHP boot. `timeZone` is an IANA identifier; the server buckets
   *  the months in it and falls back to UTC when it does not know it. */
  runHistory(timeZone: string): Observable<RunHistoryOverview> {
    return this.http.get<RunHistoryOverview>(`${this.base}/api/recommendations/runs/history`, {
      params: { tz: timeZone },
    });
  }

  /** One month's runs, newest first. Without `before` this is the month's
   *  first page; with it, the next page after that cursor. */
  runHistoryMonth(
    month: string,
    timeZone: string,
    before?: number,
  ): Observable<RunHistoryMonthPage> {
    const params: Record<string, string | number> = { tz: timeZone };
    if (before !== undefined) params['before'] = before;
    return this.http.get<RunHistoryMonthPage>(
      `${this.base}/api/recommendations/runs/history/${month}`,
      { params },
    );
  }

  /** Deletes every persisted for-you recommendation. Refuses with a 409
   *  while a run is pending or running -- purging out from under an
   *  in-flight run would leave it writing picks nobody can see. */
  purgeRecommendations(): Observable<RecommendationRunReport> {
    return this.http.delete<RecommendationRunReport>(`${this.base}/api/recommendations/runs`);
  }
}
