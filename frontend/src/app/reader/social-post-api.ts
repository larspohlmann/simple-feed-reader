import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import {
  ComposerContextDto,
  EntryLikeDto,
  EntryRepostDto,
  PublishedPostDto,
  PostDraftDto,
  PostTargetDto,
  SocialCountsDto,
  SocialViewerDto,
} from './entry-social';
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

  composer(target: PostTargetDto, accountId?: number): Observable<ComposerContextDto> {
    const path = target.kind === 'link' ? 'social/link-composer' : 'social/composer';
    return this.http.get<ComposerContextDto>(this.entryPath(target.entryId, path), {
      params: {
        ...composerParams(target),
        ...(accountId === undefined ? {} : { accountId }),
      },
    });
  }

  send(target: PostTargetDto, draft: PostDraftDto): Observable<PublishedPostDto> {
    const path = this.entryPath(target.entryId, sendPath(target));
    return this.http.post<PublishedPostDto>(path, draft, { params: sendParams(target) });
  }

  deleteReply(entryId: number, address: string): Observable<void> {
    return this.http.delete<void>(this.entryPath(entryId, 'comments/reply'), {
      params: { address },
    });
  }

  private entryPath(entryId: number, action: string): string {
    return `${this.base}/api/entries/${entryId}/${action}`;
  }
}

function composerParams(target: PostTargetDto): Record<string, string> {
  if (target.kind === 'link') return { network: target.network };
  return { kind: target.kind, ...addressParam(target.address) };
}

function sendPath(target: PostTargetDto): string {
  switch (target.kind) {
    case 'link':
      return 'social/links';
    case 'quote':
      return 'social/quotes';
    case 'reply':
      return 'social/replies';
  }
}

function sendParams(target: PostTargetDto): Record<string, string> {
  return target.kind === 'link' ? { network: target.network } : addressParam(target.address);
}

function addressParam(address: string | null): Record<string, string> {
  return address === null ? {} : { address };
}
