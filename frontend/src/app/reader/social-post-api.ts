import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import {
  EntryLikeDto,
  EntryPostLikeDto,
  EntryRepostDto,
  PostDeletionDto,
  SocialCountsDto,
  SocialViewerDto,
} from './entry-social';
import { refreshParams } from './refresh-params';
import { VoteValue } from './vote-pill/vote';

export interface VoteAnswer {
  myVote: VoteValue;
  score: number;
}

/** What the reader sees of and does to an entry's network post, and to the replies in its thread. */
@Injectable({ providedIn: 'root' })
export class SocialPostApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  counts(entryId: number, refresh = false): Observable<SocialCountsDto> {
    return this.http.get<SocialCountsDto>(this.entryPath(entryId, 'social'), {
      params: refreshParams(refresh),
    });
  }

  viewer(entryId: number): Observable<SocialViewerDto> {
    return this.http.get<SocialViewerDto>(this.entryPath(entryId, 'social/viewer'));
  }

  like(entryId: number): Observable<EntryPostLikeDto> {
    return this.http.put<EntryPostLikeDto>(this.entryPath(entryId, 'like'), null);
  }

  unlike(entryId: number): Observable<EntryPostLikeDto> {
    return this.http.delete<EntryPostLikeDto>(this.entryPath(entryId, 'like'));
  }

  repost(entryId: number): Observable<EntryRepostDto> {
    return this.http.put<EntryRepostDto>(this.entryPath(entryId, 'repost'), null);
  }

  unrepost(entryId: number): Observable<EntryRepostDto> {
    return this.http.delete<EntryRepostDto>(this.entryPath(entryId, 'repost'));
  }

  likeComment(entryId: number, address: string): Observable<EntryLikeDto> {
    return this.http.put<EntryLikeDto>(this.entryPath(entryId, 'comments/like'), null, {
      params: { address },
    });
  }

  unlikeComment(entryId: number, address: string): Observable<EntryLikeDto> {
    return this.http.delete<EntryLikeDto>(this.entryPath(entryId, 'comments/like'), {
      params: { address },
    });
  }

  repostComment(entryId: number, address: string): Observable<EntryRepostDto> {
    return this.http.put<EntryRepostDto>(this.entryPath(entryId, 'comments/repost'), null, {
      params: { address },
    });
  }

  unrepostComment(entryId: number, address: string): Observable<EntryRepostDto> {
    return this.http.delete<EntryRepostDto>(this.entryPath(entryId, 'comments/repost'), {
      params: { address },
    });
  }

  vote(entryId: number, score: VoteValue): Observable<VoteAnswer> {
    return this.http.put<VoteAnswer>(this.entryPath(entryId, 'vote'), { score });
  }

  myVote(entryId: number): Observable<{ myVote: VoteValue }> {
    return this.http.get<{ myVote: VoteValue }>(this.entryPath(entryId, 'vote'));
  }

  voteComment(entryId: number, address: string, score: VoteValue): Observable<VoteAnswer> {
    return this.http.put<VoteAnswer>(
      this.entryPath(entryId, 'comments/vote'),
      { score },
      { params: { address } },
    );
  }

  deleteReply(entryId: number, address: string): Observable<void> {
    return this.http.delete<void>(this.entryPath(entryId, 'comments/reply'), {
      params: { address },
    });
  }

  deletePost(entryId: number): Observable<PostDeletionDto> {
    return this.http.delete<PostDeletionDto>(this.entryPath(entryId, 'post'));
  }

  private entryPath(entryId: number, action: string): string {
    return `${this.base}/api/entries/${entryId}/${action}`;
  }
}
