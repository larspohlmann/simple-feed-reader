// src/app/core/client-error-reporter.ts
import { Injectable, inject } from '@angular/core';
import { Router } from '@angular/router';
import { API_BASE_URL } from './api';
import { ClientErrorItem, buildVersionTag, sendClientError } from './client-error-beacon';
import { TokenStore } from './token.store';

const DEDUPE_WINDOW_MS = 10_000;
const RATE_WINDOW_MS = 60_000;
const MAX_REPORTS_PER_WINDOW = 20;

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
  private rateWindowStartedAt = 0;
  private reportsSentInWindow = 0;

  report(error: unknown, kind = 'Error'): void {
    try {
      const item = this.toWireItem(error, kind);
      if (this.isSuppressed(item)) {
        return;
      }
      sendClientError(item, {
        url: `${this.baseUrl}/api/client-errors`,
        bearerToken: this.tokens.token(),
      });
    } catch {
      // A reporter that throws would defeat the handler that called it.
    }
  }

  private toWireItem(error: unknown, kind: string): ClientErrorItem {
    const normalized = error instanceof Error ? error : new Error(String(error));
    return {
      message: normalized.message || String(error),
      stack: normalized.stack ?? null,
      kind: error instanceof Error ? error.name : kind,
      url: window.location.href,
      route: this.router.url,
      buildVersion: buildVersionTag(),
      userAgent: navigator.userAgent,
      at: new Date().toISOString(),
    };
  }

  /** Collapses a flood from one broken render: the same signature is dropped
   *  inside the dedupe window, and the window-wide cap drops the rest. */
  private isSuppressed(item: ClientErrorItem): boolean {
    if (this.rateLimitExceeded()) {
      return true;
    }

    const signature = this.signatureOf(item);
    const lastSentAt = this.lastSentAtBySignature.get(signature);
    if (lastSentAt !== undefined && Date.now() - lastSentAt < DEDUPE_WINDOW_MS) {
      return true;
    }

    this.lastSentAtBySignature.set(signature, Date.now());
    this.reportsSentInWindow += 1;
    return false;
  }

  private rateLimitExceeded(): boolean {
    const now = Date.now();
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
