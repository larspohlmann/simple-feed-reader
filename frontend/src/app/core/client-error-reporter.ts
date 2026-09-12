// src/app/core/client-error-reporter.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Router } from '@angular/router';
import { API_BASE_URL } from './api';
import {
  CLIENT_ERRORS_PATH,
  ClientErrorItem,
  ErrorDescription,
  describeError,
  sendClientError,
  toClientErrorItem,
} from './client-error-beacon';
import { httpMethodOf } from './client-error-http-method';
import { TokenStore } from './token.store';

function stripQueryAndFragment(url: string): string {
  return url.split(/[?#]/)[0];
}

function describeHttpError(error: HttpErrorResponse): ErrorDescription {
  const url = stripQueryAndFragment(error.url ?? 'unknown');
  const method = httpMethodOf(error);
  const isParseErrorOnSuccess = error.status >= 200 && error.status < 300;
  const parseNote = isParseErrorOnSuccess ? ' (response parse error)' : '';
  const message = `HTTP ${error.status}${method ? ' ' + method : ''} ${url}${parseNote}`;
  return { message, stack: null, kind: 'HttpError' };
}

export const DEDUPE_WINDOW_MS = 10_000;
export const RATE_WINDOW_MS = 60_000;
export const MAX_REPORTS_PER_WINDOW = 20;

/**
 * The one root sink for reportable frontend errors (#984). Tags each report
 * with the route, build version, and user agent, attaches the bearer so the
 * backend can resolve the user, and dedupes/throttles so one broken render
 * cannot flood the endpoint. Delivery is fire-and-forget and never throws back.
 */
@Injectable({ providedIn: 'root' })
export class ClientErrorReporter {
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly router = inject(Router);
  private readonly tokens = inject(TokenStore);

  private readonly lastSentAtBySignature = new Map<string, number>();
  private readonly reportedObjects = new WeakSet<object>();
  private rateWindowStartedAt = 0;
  private reportsSentInWindow = 0;

  report(error: unknown): void {
    try {
      if (typeof error === 'object' && error !== null) {
        if (this.reportedObjects.has(error)) {
          return;
        }
        this.reportedObjects.add(error);
      }
      const item = this.toWireItem(error);
      if (this.isSuppressed(item)) {
        return;
      }
      sendClientError(item, {
        url: `${this.baseUrl}/${CLIENT_ERRORS_PATH}`,
        bearerToken: this.tokens.token(),
      });
    } catch {
      // A reporter that throws would defeat the handler that called it.
    }
  }

  private toWireItem(error: unknown): ClientErrorItem {
    const description =
      error instanceof HttpErrorResponse ? describeHttpError(error) : describeError(error);
    return toClientErrorItem(description, { route: this.router.url });
  }

  /** Collapses a flood from one broken render: the same signature is dropped
   *  inside the dedupe window, and the window-wide cap drops the rest. Also
   *  sweeps signatures that aged out, so a singleton living for a whole tab
   *  session never accumulates one entry per distinct message forever. */
  private isSuppressed(item: ClientErrorItem): boolean {
    const now = Date.now();
    this.forgetSignaturesOlderThan(now - DEDUPE_WINDOW_MS);

    if (this.rateLimitExceeded(now)) {
      return true;
    }

    const signature = this.signatureOf(item);
    const lastSentAt = this.lastSentAtBySignature.get(signature);
    if (lastSentAt !== undefined && now - lastSentAt < DEDUPE_WINDOW_MS) {
      return true;
    }

    this.lastSentAtBySignature.set(signature, now);
    this.reportsSentInWindow += 1;
    return false;
  }

  private forgetSignaturesOlderThan(cutoff: number): void {
    for (const [signature, sentAt] of this.lastSentAtBySignature) {
      if (sentAt < cutoff) {
        this.lastSentAtBySignature.delete(signature);
      }
    }
  }

  private rateLimitExceeded(now: number): boolean {
    if (now - this.rateWindowStartedAt > RATE_WINDOW_MS) {
      this.rateWindowStartedAt = now;
      this.reportsSentInWindow = 0;
    }
    return this.reportsSentInWindow >= MAX_REPORTS_PER_WINDOW;
  }

  private signatureOf(item: ClientErrorItem): string {
    const firstStackLine = (item.stack ?? '').split('\n')[0];
    return `${item.kind}::${item.message}::${firstStackLine}`;
  }
}
