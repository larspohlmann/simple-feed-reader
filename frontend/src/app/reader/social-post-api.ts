import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { EntryLikeDto, EntryRepostDto, SocialCountsDto, SocialViewerDto } from './entry-social';
import { refreshParams } from './refresh-params';

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

  like(entryId: number): Observable<EntryLikeDto> {
    return this.http.put<EntryLikeDto>(this.entryPath(entryId, 'like'), null);
  }

  unlike(entryId: number): Observable<EntryLikeDto> {
    return this.http.delete<EntryLikeDto>(this.entryPath(entryId, 'like'));
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

  private entryPath(entryId: number, action: string): string {
    return `${this.base}/api/entries/${entryId}/${action}`;
  }
}
