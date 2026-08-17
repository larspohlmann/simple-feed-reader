// src/app/core/problem.ts
import { HttpErrorResponse } from '@angular/common/http';

export interface Problem {
  type: string;
  title: string;
  status: number;
  detail?: string;
  errors?: Record<string, string[]>;
  accountStatus?: string;
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
  const body: unknown = err.error;
  if (body && typeof body === 'object' && 'type' in body) {
    const b = body as Record<string, unknown>;
    return {
      type: String(b['type'] ?? 'about:blank'),
      title: String(b['title'] ?? 'Request failed'),
      status: typeof b['status'] === 'number' ? (b['status'] as number) : err.status,
      detail: typeof b['detail'] === 'string' ? (b['detail'] as string) : undefined,
      errors: (b['errors'] as Record<string, string[]> | undefined) ?? undefined,
      accountStatus:
        typeof b['accountStatus'] === 'string' ? (b['accountStatus'] as string) : undefined,
    };
  }
  return {
    type: err.status === 413 ? REQUEST_TOO_LARGE : 'about:blank',
    title: fallbackTitle(err.status),
    status: err.status,
  };
}

function fallbackTitle(status: number): string {
  if (status === 0) return 'Could not reach the server';
  if (status === 413) return 'The file is too large for this server to accept';
  return 'Something went wrong';
}
