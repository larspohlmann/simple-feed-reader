// src/app/core/problem.ts
import { HttpErrorResponse } from '@angular/common/http';

export interface Problem {
  type: string;
  title: string;
  status: number;
  detail?: string;
  errors?: Record<string, string[]>;
  accountStatus?: string;
  /** Set only on the 409 a passkey relying-party id change raises while
   *  credentials still exist -- the count the confirmation prompt quotes
   *  (RelyingPartyChangeRequiresConfirmationException, #624). */
  invalidatedPasskeyCount?: number;
  /** Set only by `PasskeyService.toProblem()`'s `DOMException`/local-`Error`
   *  branch (#624), never by `parseProblem()`/`fallbackProblem()`. Needed
   *  because `status: 0` alone is ambiguous: both a rejected ceremony and a
   *  genuine dropped connection produce it, but `title` differs -- raw
   *  `DOMException.message` in the first, this app's translated "Could not
   *  reach the server" in the second -- so a caller hiding one must not hide
   *  the other. (`outcomeIsUnproven()` below reads
   *  plain `status === 0` differently, confirming the status alone cannot be
   *  the discriminator here.) */
  ceremonyRejected?: true;
}

/** A request whose outcome the response cannot prove: a dropped connection
 *  (status 0) or a 5xx -- gateway timeout, OOM-killed worker -- may have run
 *  server-side before it failed. Callers that must not act as if the server
 *  refused (a wipe, an irreversible browser signal) branch on this. */
export function outcomeIsUnproven(problem: Problem): boolean {
  return problem.status === 0 || problem.status >= 500;
}

/** An oversized request body, refused by the web server before the app ran;
 *  features that upload a file match on this to offer their own wording (#458). */
export const REQUEST_TOO_LARGE = 'request_too_large';

/** Map any HttpErrorResponse to the backend's problem+json contract, with a
 *  fallback that names what the response contained when the body is missing
 *  or not problem+json (network errors, gateways, web-server pages). */
export function parseProblem(err: HttpErrorResponse): Problem {
  return problemFromBody(err.error, err.status) ?? fallbackProblem(err, responseText(err.error));
}

/** Read an error body that Angular delivered as a Blob before mapping it. */
export async function parseProblemAsync(err: HttpErrorResponse): Promise<Problem> {
  if (!(err.error instanceof Blob)) return parseProblem(err);

  const text = await err.error.text().catch(() => '');
  return problemFromBody(parseJsonOrNull(text), err.status) ?? fallbackProblem(err, text);
}

function problemFromBody(body: unknown, status: number): Problem | null {
  if (!body || body instanceof Blob || typeof body !== 'object' || !('type' in body)) return null;

  const b = body as Record<string, unknown>;
  return {
    type: String(b['type'] ?? 'about:blank'),
    title: String(b['title'] ?? 'Request failed'),
    status: typeof b['status'] === 'number' ? (b['status'] as number) : status,
    detail: typeof b['detail'] === 'string' ? (b['detail'] as string) : undefined,
    errors: (b['errors'] as Record<string, string[]> | undefined) ?? undefined,
    accountStatus:
      typeof b['accountStatus'] === 'string' ? (b['accountStatus'] as string) : undefined,
    invalidatedPasskeyCount:
      typeof b['invalidatedPasskeyCount'] === 'number'
        ? (b['invalidatedPasskeyCount'] as number)
        : undefined,
  };
}

function parseJsonOrNull(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

/** A 2xx body that failed JSON.parse arrives as Angular's `{ error, text }`;
 *  a non-2xx body it could not parse stays a plain string. */
function responseText(body: unknown): string {
  if (typeof body === 'string') return body;
  if (body && typeof body === 'object' && 'text' in body && typeof body.text === 'string') {
    return body.text;
  }
  return '';
}

function fallbackProblem(err: HttpErrorResponse, text: string): Problem {
  if (err.status === 413) {
    return {
      type: REQUEST_TOO_LARGE,
      title: 'The file is too large for this server to accept',
      status: err.status,
    };
  }
  if (err.status === 0) {
    return { type: 'about:blank', title: 'Could not reach the server', status: err.status };
  }
  return { type: 'about:blank', title: unexpectedReplyTitle(err, text), status: err.status };
}

function unexpectedReplyTitle(err: HttpErrorResponse, text: string): string {
  const pageTitle = htmlPageTitle(text);
  if (pageTitle === null) {
    return `The server answered with an unexpected reply (HTTP ${err.status}).`;
  }

  return `The web server answered "${pageTitle}" instead of the app. Try again in a minute.`;
}

function htmlPageTitle(text: string): string | null {
  const title = /<title[^>]*>([^<]*)<\/title>/i.exec(text)?.[1]?.replace(/\s+/g, ' ').trim();
  return title ? title : null;
}
