import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { ComposeRequest, ResponseKind } from './compose-request';
import { ComposerContextDto, PostDraftDto, PublishedPostDto } from './composer-dto';

const RESPONSE_PATHS: Record<ResponseKind, string> = { reply: 'replies', quote: 'quotes' };

/** What the composer asks and sends: the context of a post to write and the post once written. */
@Injectable({ providedIn: 'root' })
export class ComposerApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  context(request: ComposeRequest, accountId?: number): Observable<ComposerContextDto> {
    const path =
      request.kind === 'link'
        ? `${this.base}/api/social/link-composer`
        : `${this.base}/api/entries/${request.entryId}/social/composer`;
    return this.http.get<ComposerContextDto>(path, {
      params: {
        ...composerParams(request),
        ...(accountId === undefined ? {} : { accountId }),
      },
    });
  }

  send(request: ComposeRequest, draft: PostDraftDto): Observable<PublishedPostDto> {
    if (request.kind === 'link') {
      return this.http.post<PublishedPostDto>(`${this.base}/api/social/links`, {
        ...draft,
        network: request.network,
        url: request.url,
      });
    }
    return this.http.post<PublishedPostDto>(
      `${this.base}/api/entries/${request.entryId}/social/${RESPONSE_PATHS[request.kind]}`,
      draft,
      { params: addressParam(request.address) },
    );
  }
}

function targetParams(request: ComposeRequest): Record<string, string> {
  if (request.kind === 'link') {
    return { network: request.network, url: request.url };
  }
  return addressParam(request.address);
}

function composerParams(request: ComposeRequest): Record<string, string> {
  if (request.kind !== 'link') return { kind: request.kind, ...targetParams(request) };
  const quote: Record<string, string> = request.quote === null ? {} : { quote: request.quote };
  return { ...targetParams(request), title: request.title, ...quote };
}

function addressParam(address: string | null): Record<string, string> {
  return address === null ? {} : { address };
}
