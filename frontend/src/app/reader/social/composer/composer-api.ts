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
    return this.http.get<ComposerContextDto>(this.contextPath(request), {
      params: {
        ...composerParams(request),
        ...(accountId === undefined ? {} : { accountId }),
      },
    });
  }

  send(request: ComposeRequest, draft: PostDraftDto): Observable<PublishedPostDto> {
    switch (request.kind) {
      case 'link':
        return this.http.post<PublishedPostDto>(`${this.base}/api/social/links`, {
          ...draft,
          network: request.network,
          url: request.url,
        });
      case 'reply':
      case 'quote':
        return this.http.post<PublishedPostDto>(
          `${this.base}/api/entries/${request.entryId}/social/${RESPONSE_PATHS[request.kind]}`,
          draft,
          { params: addressParam(request.address) },
        );
      default:
        return assertNever(request);
    }
  }

  private contextPath(request: ComposeRequest): string {
    switch (request.kind) {
      case 'link':
        return `${this.base}/api/social/link-composer`;
      case 'reply':
      case 'quote':
        return `${this.base}/api/entries/${request.entryId}/social/composer`;
      default:
        return assertNever(request);
    }
  }
}

function targetParams(request: ComposeRequest): Record<string, string> {
  switch (request.kind) {
    case 'link':
      return { network: request.network, url: request.url };
    case 'reply':
    case 'quote':
      return addressParam(request.address);
    default:
      return assertNever(request);
  }
}

function composerParams(request: ComposeRequest): Record<string, string> {
  switch (request.kind) {
    case 'link': {
      const quote: Record<string, string> = request.quote === null ? {} : { quote: request.quote };
      return { ...targetParams(request), title: request.title, ...quote };
    }
    case 'reply':
    case 'quote':
      return { kind: request.kind, ...targetParams(request) };
    default:
      return assertNever(request);
  }
}

function addressParam(address: string | null): Record<string, string> {
  return address === null ? {} : { address };
}

function assertNever(request: never): never {
  throw new Error(`Unhandled composer request: ${JSON.stringify(request)}`);
}
