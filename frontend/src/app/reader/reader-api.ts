import { SocialNetworkName } from '../core/social-network';
import { SavedSearchDraft, SavedSearchesResponse } from './saved-search-api-shapes';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { retryingTransientGatewayErrors } from '../core/http/retry-transient-gateway-errors';
import { PAGE_SIZE } from './list/paging';
import { SignedUrl } from './query/link-param';
import { RefreshScope, ScopedMarkReadTarget } from './query/query';
import { EntryLikeDto, SocialCountsDto } from './entry-social';
import { CompletedEntryDto, EntryDetailResponse } from './state/completed-entry';
import { CommentsResponse } from './entry-comment';
import {
  EntriesPage,
  EntryQuery,
  EntryStatePatch,
  FeedPreview,
  MoveFeedToTag,
  ReaderContent,
  RecommendationRunReport,
  RefreshReport,
  SavedSearchWire,
  SubscribeResult,
  SubscriptionDto,
  SubscriptionCountsResponse,
  SubscriptionsResponse,
  SubscriptionUpdate,
  EntryStateDto,
  TagDto,
  TagInput,
} from './models';
import { BulkSubscriptionUpdate } from './subscription-bulk';

export interface SubscribeRequest {
  url: string;
  format?: string;
  tagIds?: number[];
  title?: string;
}

@Injectable({ providedIn: 'root' })
export class ReaderApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  subscriptions(): Observable<SubscriptionsResponse> {
    return this.http.get<SubscriptionsResponse>(`${this.base}/api/subscriptions`, {
      context: retryingTransientGatewayErrors(),
    });
  }

  /** The sidebar poll's cheap tick (#720): counts only, no feeds or tags. */
  subscriptionCounts(): Observable<SubscriptionCountsResponse> {
    return this.http.get<SubscriptionCountsResponse>(`${this.base}/api/subscriptions/counts`, {
      context: retryingTransientGatewayErrors(),
    });
  }

  subscribe(request: SubscribeRequest): Observable<SubscribeResult> {
    const { url, format, tagIds, title } = request;
    const body: SubscribeRequest = { url };
    if (format) body.format = format;
    // Omit an empty selection so the body stays byte-compatible with clients
    // (and tests) that never send tags.
    if (tagIds && tagIds.length > 0) body.tagIds = tagIds;
    if (title) body.title = title;
    return this.http.post<SubscribeResult>(`${this.base}/api/subscriptions`, body);
  }

  /** A single entry by id, full body included — lets a deep link open an entry
   *  not in the loaded page, and backs the reader body store. */
  entry(id: number): Observable<EntryDetailResponse> {
    return this.http.get<EntryDetailResponse>(`${this.base}/api/entries/${id}`);
  }

  entries(query: EntryQuery, cursor?: string | null): Observable<EntriesPage> {
    const params = this.pageParams(query, cursor);
    const context = retryingTransientGatewayErrors();
    if (query.savedSearchId != null) {
      return this.http.get<EntriesPage>(
        `${this.base}/api/entries/saved-searches/${query.savedSearchId}`,
        { params, context },
      );
    }
    if (query.q) {
      return this.http.get<EntriesPage>(`${this.base}/api/entries/search`, {
        params: params.set('q', query.q),
        context,
      });
    }
    if (query.view === 'saved-searches') {
      return this.http.get<EntriesPage>(`${this.base}/api/entries/saved-searches`, {
        params,
        context,
      });
    }
    let listParams = params.set('view', query.view);
    if (query.subscription != null) listParams = listParams.set('subscription', query.subscription);
    if (query.tag != null) listParams = listParams.set('tag', query.tag);
    if (query.network != null) listParams = listParams.set('network', query.network);
    if (query.feedKind != null) listParams = listParams.set('feedKind', query.feedKind);
    if (query.group != null) listParams = listParams.set('group', query.group);
    return this.http.get<EntriesPage>(`${this.base}/api/entries`, { params: listParams, context });
  }

  private pageParams(query: EntryQuery, cursor?: string | null): HttpParams {
    let params = new HttpParams().set('limit', PAGE_SIZE);
    if (query.unread) params = params.set('unread', '1');
    if (query.order === 'oldest') params = params.set('order', 'asc');
    if (cursor) params = params.set('cursor', cursor);
    return params;
  }

  updateState(id: number, patch: EntryStatePatch): Observable<{ state: EntryStateDto }> {
    return this.http.patch<{ state: EntryStateDto }>(`${this.base}/api/entries/${id}/state`, patch);
  }

  likeState(id: number): Observable<EntryLikeDto> {
    return this.http.get<EntryLikeDto>(`${this.base}/api/entries/${id}/like`);
  }

  like(id: number): Observable<EntryLikeDto> {
    return this.http.put<EntryLikeDto>(`${this.base}/api/entries/${id}/like`, null);
  }

  unlike(id: number): Observable<EntryLikeDto> {
    return this.http.delete<EntryLikeDto>(`${this.base}/api/entries/${id}/like`);
  }

  likeComment(entryId: number, address: string): Observable<EntryLikeDto> {
    return this.http.put<EntryLikeDto>(`${this.base}/api/entries/${entryId}/comments/like`, null, {
      params: { address },
    });
  }

  unlikeComment(entryId: number, address: string): Observable<EntryLikeDto> {
    return this.http.delete<EntryLikeDto>(`${this.base}/api/entries/${entryId}/comments/like`, {
      params: { address },
    });
  }

  markRead(target: ScopedMarkReadTarget, until: string): Observable<void> {
    return this.http.post<void>(`${this.base}/api/entries/mark-read`, { ...target, until });
  }

  markSearchRead(term: string, until: string): Observable<void> {
    return this.http.post<void>(`${this.base}/api/entries/search/mark-read`, { q: term, until });
  }

  /** For you names no scope: the list is the caller's own ranked feed, and the
   *  backend marks the picks by entry state rather than by watermark (#710). */
  markForYouRead(until: string): Observable<void> {
    return this.http.post<void>(`${this.base}/api/entries/for-you/mark-read`, { until });
  }

  /** The combined saved-search list names no scope: the backend marks the
   *  matches by entry state, as the single-search mark-read does (#769). */
  markSavedSearchesRead(until: string): Observable<void> {
    return this.http.post<void>(`${this.base}/api/entries/saved-searches/mark-read`, { until });
  }

  markSingleSavedSearchRead(id: number, until: string): Observable<void> {
    return this.http.post<void>(`${this.base}/api/entries/saved-searches/${id}/mark-read`, {
      until,
    });
  }

  /** Mark an explicit set of entries read. Context-agnostic: the id list is
   *  self-describing, so it serves every list including the ranked ones. */
  markEntriesRead(ids: number[]): Observable<void> {
    return this.http.post<void>(`${this.base}/api/entries/mark-read-batch`, { ids });
  }

  /** Finishes the given pending entries (at most 25) and answers with what that filled in (#1502, #1551). */
  complete(ids: number[]): Observable<CompletedEntryDto[]> {
    return this.http
      .post<{ entries: CompletedEntryDto[] }>(`${this.base}/api/entries/complete`, { ids })
      .pipe(map((response) => response.entries));
  }

  readerContent(entryId: number): Observable<ReaderContent> {
    return this.http.get<ReaderContent>(`${this.base}/api/entries/${entryId}/reader`);
  }

  signedReaderContent(target: SignedUrl): Observable<ReaderContent> {
    return this.http.get<ReaderContent>(`${this.base}/api/reader`, {
      params: { url: target.url, token: target.token },
    });
  }

  comments(entryId: number, refresh = false): Observable<CommentsResponse> {
    return this.http.get<CommentsResponse>(`${this.base}/api/entries/${entryId}/comments`, {
      params: refreshParams(refresh),
    });
  }

  socialCounts(entryId: number, refresh = false): Observable<SocialCountsDto> {
    return this.http.get<SocialCountsDto>(`${this.base}/api/entries/${entryId}/social`, {
      params: refreshParams(refresh),
    });
  }

  /** Omit the scope (or pass an empty one) to refresh all the caller's due
   *  feeds; scope by feedId for a single feed (e.g. a just-added one) or by
   *  tagId for every feed carrying that tag, by network for that network's feeds, or by
   *  group for a sidebar section's feeds. */
  refresh(scope?: RefreshScope): Observable<RefreshReport> {
    let params = new HttpParams();
    if (scope?.feedId != null) params = params.set('feedId', scope.feedId);
    else if (scope?.tagId != null) params = params.set('tag', scope.tagId);
    else if (scope?.network != null) {
      params = params.set('network', scope.network);
      if (scope.feedKind != null) params = params.set('feedKind', scope.feedKind);
    } else if (scope?.group != null) params = params.set('group', scope.group);
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

  /** Move a feed between lists at the dropped position (see {@link MoveFeedToTag}). */
  moveFeedToTag(id: number, body: MoveFeedToTag): Observable<{ subscription: SubscriptionDto }> {
    return this.http.patch<{ subscription: SubscriptionDto }>(
      `${this.base}/api/subscriptions/${id}/move-to-tag`,
      body,
    );
  }

  /** Persist the untagged "Feeds" order. */
  reorderSubscriptions(subscriptionIds: number[]): Observable<void> {
    return this.http.patch<void>(`${this.base}/api/subscriptions/reorder`, { subscriptionIds });
  }

  /** Change tags and inclusion flags across many feeds in one request. */
  bulkUpdateSubscriptions(
    body: BulkSubscriptionUpdate,
  ): Observable<{ subscriptions: SubscriptionDto[] }> {
    return this.http.patch<{ subscriptions: SubscriptionDto[] }>(
      `${this.base}/api/subscriptions/bulk`,
      body,
    );
  }

  /** Unsubscribe from many feeds in one request; answers how many went. */
  bulkUnsubscribe(subscriptionIds: number[]): Observable<{ removed: number }> {
    return this.http.post<{ removed: number }>(`${this.base}/api/subscriptions/bulk-unsubscribe`, {
      subscriptionIds,
    });
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

  /** Persist one sibling set's order: all roots, or all children of one parent. */
  reorderTags(tagIds: readonly number[]): Observable<{ tags: TagDto[] }> {
    return this.http.patch<{ tags: TagDto[] }>(`${this.base}/api/tags/reorder`, { tagIds });
  }

  /** Persist the order of feeds within one tag. */
  setTagFeedOrder(tagId: number, subscriptionIds: number[]): Observable<void> {
    return this.http.patch<void>(`${this.base}/api/tags/${tagId}/feed-order`, { subscriptionIds });
  }

  savedSearches(): Observable<SavedSearchesResponse> {
    return this.http.get<SavedSearchesResponse>(`${this.base}/api/saved-searches`, {
      context: retryingTransientGatewayErrors(),
    });
  }

  includeNetworkInSavedSearches(network: SocialNetworkName): Observable<void> {
    return this.http.put<void>(
      `${this.base}/api/saved-searches/included-networks/${network}`,
      null,
    );
  }

  excludeNetworkFromSavedSearches(network: SocialNetworkName): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/saved-searches/included-networks/${network}`);
  }

  createSavedSearch(body: SavedSearchDraft): Observable<{ savedSearch: SavedSearchWire }> {
    return this.http.post<{ savedSearch: SavedSearchWire }>(
      `${this.base}/api/saved-searches`,
      body,
    );
  }

  deleteSavedSearch(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/saved-searches/${id}`);
  }

  updateSavedSearch(
    id: number,
    body: { includeInDigest: boolean },
  ): Observable<{ savedSearch: SavedSearchWire }> {
    return this.http.patch<{ savedSearch: SavedSearchWire }>(
      `${this.base}/api/saved-searches/${id}`,
      body,
    );
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

  /** Deletes every persisted for-you recommendation. Refuses with a 409
   *  while a run is pending or running -- purging out from under an
   *  in-flight run would leave it writing picks nobody can see. */
  purgeRecommendations(): Observable<RecommendationRunReport> {
    return this.http.delete<RecommendationRunReport>(`${this.base}/api/recommendations/runs`);
  }
}

function refreshParams(refresh: boolean): Record<string, string> {
  return refresh ? { refresh: '1' } : {};
}
