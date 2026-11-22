import { HttpClient } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../../../core/api';
import { ComposeRequest, ResponseKind } from './compose-request';
import { ComposerContextDto, PostDraftDto, PublishedPostDto } from './composer-dto';

const RESPONSE_PATHS: Record<ResponseKind, string> = { reply: 'replies', quote: 'quotes' };

interface ComposerRoute {
  contextPath: string;
  contextParams: Record<string, string>;
  sendPath: string;
  sendParams: Record<string, string>;
  sendBody: (draft: PostDraftDto) => object;
}

/** What the composer asks and sends: the context of a post to write and the post once written. */
@Injectable({ providedIn: 'root' })
export class ComposerApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  context(request: ComposeRequest, accountId?: number): Observable<ComposerContextDto> {
    const route = this.route(request);
    return this.http.get<ComposerContextDto>(route.contextPath, {
      params: {
        ...route.contextParams,
        ...(accountId === undefined ? {} : { accountId }),
      },
    });
  }

  send(request: ComposeRequest, draft: PostDraftDto): Observable<PublishedPostDto> {
    const route = this.route(request);
    return this.http.post<PublishedPostDto>(route.sendPath, route.sendBody(draft), {
      params: route.sendParams,
    });
  }

  private route(request: ComposeRequest): ComposerRoute {
    switch (request.kind) {
      case 'link': {
        const quote: Record<string, string> =
          request.quote === null ? {} : { quote: request.quote };
        return {
          contextPath: `${this.base}/api/social/link-composer`,
          contextParams: {
            network: request.network,
            url: request.url,
            title: request.title,
            ...quote,
          },
          sendPath: `${this.base}/api/social/links`,
          sendParams: {},
          sendBody: (draft) => ({ ...draft, network: request.network, url: request.url }),
        };
      }
      case 'post':
        return {
          contextPath: `${this.base}/api/social/post-composer`,
          contextParams: { network: request.network },
          sendPath: `${this.base}/api/social/posts`,
          sendParams: {},
          sendBody: (draft) => ({ ...draft, network: request.network }),
        };
      case 'reply':
      case 'quote': {
        const address = addressParam(request.address);
        const entryPath = `${this.base}/api/entries/${request.entryId}/social`;
        return {
          contextPath: `${entryPath}/composer`,
          contextParams: { kind: request.kind, ...address },
          sendPath: `${entryPath}/${RESPONSE_PATHS[request.kind]}`,
          sendParams: address,
          sendBody: (draft) => draft,
        };
      }
      default:
        return assertNever(request);
    }
  }
}

function addressParam(address: string | null): Record<string, string> {
  return address === null ? {} : { address };
}

function assertNever(request: never): never {
  throw new Error(`Unhandled composer request: ${JSON.stringify(request)}`);
}
