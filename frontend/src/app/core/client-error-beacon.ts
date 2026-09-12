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

function constructorNameOf(error: unknown): string {
  return (error as { constructor?: { name?: string } })?.constructor?.name || 'Object';
}

/** Bounded, value-free: object VALUES may hold secrets the scrubber does not
 *  know about, but the KEYS are safe to report and still useful for triage. */
function stringifyUnknown(error: unknown): string {
  if (typeof error !== 'object' || error === null) {
    return String(error);
  }
  const keys = Object.keys(error).slice(0, 20);
  return `${constructorNameOf(error)}{${keys.join(', ')}}`;
}

function isErrorLike(error: unknown): error is { message: string; name: string; stack?: unknown } {
  return (
    error instanceof Error ||
    (typeof error === 'object' &&
      error !== null &&
      typeof (error as { message?: unknown }).message === 'string' &&
      typeof (error as { name?: unknown }).name === 'string')
  );
}

/** Turns any thrown value into wire-ready content, never `[object Object]`,
 *  and never throws itself — a revoked Proxy or a throwing getter must not
 *  escape the error-reporting path. */
export function describeError(error: unknown, fallbackKind = 'Error'): ErrorDescription {
  try {
    if (isErrorLike(error)) {
      return {
        message: error.message || error.name || fallbackKind,
        stack: typeof error.stack === 'string' ? error.stack : null,
        kind: error.name || fallbackKind,
      };
    }
    return { message: stringifyUnknown(error), stack: null, kind: fallbackKind };
  } catch {
    return { message: fallbackKind, stack: null, kind: fallbackKind };
  }
}

const MAX_MESSAGE = 2000;
const MAX_STACK = 8000;

function truncate(text: string, max: number): string {
  return text.length > max ? text.slice(0, max) : text;
}

/** The one place the wire shape and the backend's length caps live. */
export function toClientErrorItem(
  description: ErrorDescription,
  context: { route: string | null },
): ClientErrorItem {
  return {
    message: truncate(description.message, MAX_MESSAGE),
    stack: description.stack === null ? null : truncate(description.stack, MAX_STACK),
    kind: description.kind,
    url: typeof location !== 'undefined' ? location.href : null,
    route: context.route,
    buildVersion: buildVersionTag(),
    userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : null,
    at: new Date().toISOString(),
  };
}

/** Boot-time convenience for callers with no injector (boot-error-surface.ts).
 *  `describeError` is total and `sendClientError` is already guarded, so the
 *  boot path is throw-safe without its own try/catch. */
export function reportBootError(error: unknown): void {
  sendClientError(toClientErrorItem(describeError(error, 'BootError'), { route: null }));
}
