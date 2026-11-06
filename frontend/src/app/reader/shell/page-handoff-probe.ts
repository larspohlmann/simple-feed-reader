import { DOCUMENT } from '@angular/common';
import { DestroyRef, Injectable, NgZone, inject } from '@angular/core';
import { API_BASE_URL } from '../../core/api';
import { TokenStore } from '../../core/auth/token.store';
import {
  CLIENT_ERRORS_PATH,
  sendClientError,
  toClientErrorItem,
} from '../../core/errors/client-error-beacon';
import { ScrollHosts } from '../../shared/scroll-host/scroll-hosts.service';
import {
  PAGE_SCROLL_BLOCK_CLASS,
  observePageWrites,
} from '../../shared/scroll-host/page-scroll-host';

export type PageHandoff = 'enter' | 'leave';

/** `write` is the app setting the offset; every other type is a browser event. */
interface PageEvent {
  readonly at: number;
  readonly type: string;
  readonly top: number;
}

interface HandoffWatch {
  readonly handoff: PageHandoff;
  readonly target: number;
  readonly startedAt: number;
  readonly before: PageEvent[];
  readonly samples: { at: number; top: number }[];
  drifted: boolean;
  frame: number;
}

export const WATCH_MS = 1000;
const DRIFT_TOLERANCE_PX = 2;
const HISTORY_MS = 800;
const MAX_REPORTS = 5;
const USER_TAKEOVER = new Set(['touchstart', 'wheel']);
const RECORDED_EVENTS = ['scroll', 'touchstart', 'touchend', 'touchcancel', 'wheel'];

/** A diagnostic for #2074: reports a layer handoff whose page offset does not stay where
 *  PageScrollOwner put it, with the app's writes and the browser's events around it. */
@Injectable()
export class PageHandoffProbe {
  private readonly document = inject(DOCUMENT);
  private readonly view = this.document.defaultView ?? window;
  private readonly page = inject(ScrollHosts).forPage(this.document.body);
  private readonly baseUrl = inject(API_BASE_URL);
  private readonly tokens = inject(TokenStore);
  private readonly zone = inject(NgZone);
  private readonly events: PageEvent[] = [];
  private watching: HandoffWatch | null = null;
  private reports = 0;
  private readonly onEvent = (event: Event): void => {
    if (event.type === 'scroll' && event.target !== this.document) return;
    this.record(event.type, this.top());
  };
  private readonly stopObservingWrites: () => void;

  constructor() {
    this.zone.runOutsideAngular(() =>
      RECORDED_EVENTS.forEach((type) =>
        this.view.addEventListener(type, this.onEvent, { passive: true, capture: true }),
      ),
    );
    this.stopObservingWrites = observePageWrites((top) => this.record('write', Math.round(top)));
    inject(DestroyRef).onDestroy(() => this.detach());
  }

  /** Called right after the owner wrote `target` to the page. */
  watch(handoff: PageHandoff, target: number): void {
    this.stopWatching();
    if (this.reports >= MAX_REPORTS || this.blocked()) return;
    const startedAt = this.view.performance.now();
    const landed = this.top();
    this.watching = {
      handoff,
      target,
      startedAt,
      before: this.eventsSince(startedAt - HISTORY_MS),
      samples: [{ at: 0, top: landed }],
      drifted: this.driftsFrom(target, landed),
      frame: 0,
    };
    this.nextFrame();
  }

  private readonly step = (): void => {
    const watch = this.watching;
    if (!watch) return;
    const elapsed = this.view.performance.now() - watch.startedAt;
    if (this.userTookOverSince(watch.startedAt) || elapsed > WATCH_MS) {
      this.finish(watch);
      return;
    }
    const top = this.top();
    if (watch.samples.at(-1)?.top !== top) watch.samples.push({ at: Math.round(elapsed), top });
    watch.drifted ||= this.driftsFrom(watch.target, top);
    this.nextFrame();
  };

  private nextFrame(): void {
    const watch = this.watching;
    if (watch)
      this.zone.runOutsideAngular(() => (watch.frame = this.view.requestAnimationFrame(this.step)));
  }

  private finish(watch: HandoffWatch): void {
    this.watching = null;
    if (!watch.drifted) return;
    this.report(watch);
    if (this.reports >= MAX_REPORTS) this.detach();
  }

  private report(watch: HandoffWatch): void {
    this.reports++;
    const relative = (event: PageEvent): string =>
      `${Math.round(event.at - watch.startedAt)}:${event.type}:${event.top}`;
    const final = this.top();
    const snapshot = {
      handoff: watch.handoff,
      target: watch.target,
      final,
      maxTop: this.maxTop(),
      innerHeight: this.view.innerHeight,
      visualViewportHeight: this.view.visualViewport?.height ?? null,
      touching: this.touching(),
      before: watch.before.map(relative),
      samples: watch.samples.map((sample) => `${sample.at}:${sample.top}`),
      after: this.eventsSince(watch.startedAt).map(relative),
    };
    sendClientError(
      toClientErrorItem(
        {
          message: `Page handoff drifted (${watch.handoff}): target ${watch.target}, final ${final}`,
          stack: JSON.stringify(snapshot),
          kind: 'ScrollHandoff',
        },
        { route: null },
      ),
      { url: `${this.baseUrl}/${CLIENT_ERRORS_PATH}`, bearerToken: this.tokens.token() },
    );
  }

  private record(type: string, top: number): void {
    const at = this.view.performance.now();
    this.events.push({ at, type, top });
    while (this.events[0].at < at - HISTORY_MS - WATCH_MS) this.events.shift();
  }

  private eventsSince(since: number): PageEvent[] {
    return this.events.filter((event) => event.at >= since);
  }

  private userTookOverSince(since: number): boolean {
    return this.events.some((event) => event.at >= since && USER_TAKEOVER.has(event.type));
  }

  private touching(): boolean {
    const touches = this.events.filter((event) => event.type.startsWith('touch'));
    return touches.at(-1)?.type === 'touchstart';
  }

  /** A target past the end lands on the end; that is the browser clamping, not a drift. */
  private driftsFrom(target: number, top: number): boolean {
    return Math.abs(top - Math.min(target, this.maxTop())) > DRIFT_TOLERANCE_PX;
  }

  private top(): number {
    return Math.round(this.page.scrollTop);
  }

  private maxTop(): number {
    return Math.max(0, this.page.scrollHeight - this.page.clientHeight);
  }

  private blocked(): boolean {
    return this.document.documentElement.classList.contains(PAGE_SCROLL_BLOCK_CLASS);
  }

  private stopWatching(): void {
    if (this.watching) this.view.cancelAnimationFrame(this.watching.frame);
    this.watching = null;
  }

  private detach(): void {
    this.stopWatching();
    this.stopObservingWrites();
    RECORDED_EVENTS.forEach((type) =>
      this.view.removeEventListener(type, this.onEvent, { capture: true }),
    );
  }
}
