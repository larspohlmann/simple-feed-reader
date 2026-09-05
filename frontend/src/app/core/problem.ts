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

/** An oversized request body, refused by the web server before the app ran.
 *  nginx answers a raw 413 with an HTML page, so there is no problem+json to
 *  read and the generic fallback would call it "Something went wrong" -- which
 *  tells the user nothing about the one thing they can act on. Features that
 *  upload a file match on this to offer their own wording (#458). */
export const REQUEST_TOO_LARGE = 'request_too_large';

/** Map any HttpErrorResponse to the backend's problem+json contract, with a
 *  safe fallback when the body is missing or not JSON (network errors, gateways). */
export function parseProblem(err: HttpErrorResponse): Problem {
  return parseProblemBody(err.error, err.status);
}

/** Read an error body that Angular delivered as a Blob before mapping it. */
export async function parseProblemAsync(err: HttpErrorResponse): Promise<Problem> {
  if (!(err.error instanceof Blob)) return parseProblem(err);

  try {
    return parseProblemBody(JSON.parse(await err.error.text()), err.status);
  } catch {
    return fallbackProblem(err.status);
  }
}

function parseProblemBody(body: unknown, status: number): Problem {
  if (body && !(body instanceof Blob) && typeof body === 'object' && 'type' in body) {
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
  return fallbackProblem(status);
}

function fallbackProblem(status: number): Problem {
  if (status === 413) {
    return {
      type: REQUEST_TOO_LARGE,
      title: 'The file is too large for this server to accept',
      status,
    };
  }
  return {
    type: 'about:blank',
    title: status === 0 ? 'Could not reach the server' : 'Something went wrong',
    status,
  };
}
