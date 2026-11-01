import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import {
  ComposerContextDto,
  EntryLikeDto,
  EntryRepostDto,
  PostDeletionDto,
  PublishedPostDto,
  PostDraftDto,
  PostTargetDto,
  ResponseKind,
  SocialCountsDto,
  SocialViewerDto,
} from './entry-social';
import { refreshParams } from './refresh-params';

const RESPONSE_PATHS: Record<ResponseKind, string> = { reply: 'replies', quote: 'quotes' };

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
    const path =
      target.kind === 'link'
        ? this.socialPath('link-composer')
        : this.entryPath(target.entryId, 'social/composer');
    return this.http.get<ComposerContextDto>(path, {
      params: {
        ...composerParams(target),
        ...(accountId === undefined ? {} : { accountId }),
      },
    });
  }

  send(target: PostTargetDto, draft: PostDraftDto): Observable<PublishedPostDto> {
    const path =
      target.kind === 'link'
        ? this.socialPath('links')
        : this.entryPath(target.entryId, `social/${RESPONSE_PATHS[target.kind]}`);
    return this.http.post<PublishedPostDto>(path, draft, { params: targetParams(target) });
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

  private socialPath(action: string): string {
    return `${this.base}/api/social/${action}`;
  }
}

function targetParams(target: PostTargetDto): Record<string, string> {
  if (target.kind === 'link') {
    return { network: target.network, url: target.url };
  }
  return addressParam(target.address);
}

function composerParams(target: PostTargetDto): Record<string, string> {
  return target.kind === 'link'
    ? { ...targetParams(target), title: target.title }
    : { kind: target.kind, ...targetParams(target) };
}

function addressParam(address: string | null): Record<string, string> {
  return address === null ? {} : { address };
}
