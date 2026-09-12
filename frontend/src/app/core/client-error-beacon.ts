// src/app/core/client-error-beacon.ts
//
// No Angular imports: boot-error-surface.ts and the pre-injector boot path
// call into this module before any injector exists (#984). `buildVersion` is
// a plain constant, so importing it here keeps that promise.
//
// The default URL is resolved relative to `document.baseURI`, not a
// hardcoded absolute path: `<base href="/">` in development and
// `<base href="/reader/">` on the Strato build both turn a relative
// `api/client-errors` into the correct same-origin path.
import { buildVersion } from '../../environments/version';

export interface ClientErrorItem {
  message: string;
  stack: string | null;
  kind: string | null;
  url: string | null;
  route: string | null;
  buildVersion: string | null;
  userAgent: string | null;
  at: string | null;
}

export interface SendClientErrorOptions {
  /** Overrides the default baseURI-relative resolution, e.g. an
   *  `${API_BASE_URL}`-derived url the injectable reporter already knows is
   *  env-correct. */
  url?: string;
  bearerToken?: string | null;
}

export const CLIENT_ERRORS_PATH = 'api/client-errors';

/** The one place the three version fields become the one wire string. */
export function buildVersionTag(): string {
  return `${buildVersion.version}+${buildVersion.commit}@${buildVersion.builtAt}`;
}

/** Resolves the endpoint against the document's own `<base>`, so the same
 *  relative path lands correctly on every deployment. */
export function resolveClientErrorsUrl(): string {
  return new URL(CLIENT_ERRORS_PATH, document.baseURI).href;
}

/** True only when there is positive evidence keepalive is missing: an old
 *  browser can ship `fetch` without it, and jsdom ships neither `fetch` nor
 *  `Request` by default, so the absence of `Request` is not itself evidence. */
function keepaliveIsUnsupported(): boolean {
  return typeof Request !== 'undefined' && !('keepalive' in Request.prototype);
}

function deliver(url: string, body: string, bearerToken?: string | null): void {
  if (typeof fetch === 'function' && !keepaliveIsUnsupported()) {
    const headers: HeadersInit = bearerToken
      ? { 'Content-Type': 'application/json', Authorization: `Bearer ${bearerToken}` }
      : { 'Content-Type': 'application/json' };
    void fetch(url, { method: 'POST', keepalive: true, headers, body }).catch(() => undefined);
    return;
  }
  // The Beacon API carries no custom headers, so this path drops the bearer and is unattributed.
  navigator.sendBeacon?.(url, new Blob([body], { type: 'application/json' }));
}

/** Fire-and-forget POST of one error. Never throws back to the caller. */
export function sendClientError(item: ClientErrorItem, options: SendClientErrorOptions = {}): void {
  try {
    const url = options.url ?? resolveClientErrorsUrl();
    deliver(url, JSON.stringify({ errors: [item] }), options.bearerToken);
  } catch {
    // Reporting an error must never raise one of its own.
  }
}

export interface ErrorDescription {
  message: string;
  stack: string | null;
  kind: string;
}

function isHttpErrorResponse(
  error: unknown,
): error is { status: number; url: string | null; name: string } {
  return (
    typeof error === 'object' &&
    error !== null &&
    (error as { name?: unknown }).name === 'HttpErrorResponse' &&
    typeof (error as { status?: unknown }).status === 'number'
  );
}

function stringifyUnknown(error: unknown): string {
  if (error === undefined) {
    return 'undefined';
  }
  try {
    const json = JSON.stringify(error);
    return json && json !== '{}' ? json : Object.prototype.toString.call(error);
  } catch {
    return Object.prototype.toString.call(error);
  }
}

/** Turns any thrown value into wire-ready content, never `[object Object]`. */
export function describeError(error: unknown, fallbackKind = 'Error'): ErrorDescription {
  if (error instanceof Error) {
    return {
      message: error.message || error.name,
      stack: error.stack ?? null,
      kind: error.name || fallbackKind,
    };
  }
  if (isHttpErrorResponse(error)) {
    return {
      message: `HTTP ${error.status} ${error.url ?? 'unknown'}`,
      stack: null,
      kind: 'HttpError',
    };
  }
  const errorLike = error as { message?: unknown; name?: unknown; stack?: unknown } | null;
  if (errorLike && typeof errorLike.message === 'string' && typeof errorLike.name === 'string') {
    return {
      message: errorLike.message || errorLike.name,
      stack: typeof errorLike.stack === 'string' ? errorLike.stack : null,
      kind: errorLike.name || fallbackKind,
    };
  }
  if (typeof error === 'string') {
    return { message: error, stack: null, kind: fallbackKind };
  }
  return { message: stringifyUnknown(error), stack: null, kind: fallbackKind };
}

/** Boot-time convenience for callers with no injector (boot-error-surface.ts). */
export function reportBootError(error: unknown): void {
  const described = describeError(error, 'BootError');
  sendClientError({
    message: described.message,
    stack: described.stack,
    kind: described.kind,
    url: typeof location !== 'undefined' ? location.href : null,
    route: null,
    buildVersion: buildVersionTag(),
    userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : null,
    at: new Date().toISOString(),
  });
}
