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
