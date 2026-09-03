import { DestroyRef, Injectable, NgZone, inject } from '@angular/core';
import { API_BASE_URL } from '../core/api';
import { onIdentityChange } from '../core/session-identity';
import { SavedSearchesStore } from './saved-searches.store';
import { SIDEBAR_RELOAD_INTERVAL_MS } from './sidebar-freshness';
import { SubscriptionsStore } from './subscriptions.store';

/** How long a page may sit untouched before the poll gives up on it. The tab
 *  visibility rule already covers a backgrounded tab; this covers a reader left
 *  open and visible with nobody at the screen. Two hours outlasts a lunch break. */
export const SIDEBAR_POLL_IDLE_LIMIT_MS = 2 * 60 * 60 * 1000;

/** The floor under the change marker (#720). Every tenth tick fetches counts
 *  even when the marker hasn't moved -- five minutes, the refresh worker's own
 *  period -- so a touch site nobody wrote degrades gracefully instead of
 *  freezing a badge; worst-case staleness never exceeds the refresh interval. */
export const SIDEBAR_POLL_MARKER_FLOOR_TICKS = 10;

/** The static change marker the refresh moves on a real import (#720). Served
 *  straight off disk by the web server — no PHP, no JWT, no database — so a
 *  tick that finds it unmoved costs a conditional request and nothing more. */
const CHANGE_MARKER_PATH = '/state/counts.json';

/** What counts as "somebody is still here". `pointerdown` covers mouse, touch
 *  and pen; `wheel` covers scroll-only; `keydown` covers keyboard-only.
 *  Deliberately not `pointermove`: a nudged desk would keep an idle tab polling. */
const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel'] as const;

/**
 * Keeps the sidebar counts moving on their own while the reader is open (#708),
 * without re-fetching the 137 KB bootstrap to read four numbers (#720).
 *
 * A steady tick reads the static change marker first: unchanged (and not the
 * floor tick), it stops there; moved (or the floor), it runs the cheap
 * counts-only reload. Regaining the tab or waking an abandoned page does the
 * full reload instead, so a change made elsewhere is picked up when it's most
 * likely waiting.
 *
 * Reloads the two stores that hold every count (badges, list heading, tab
 * title all read them, #709), so one tick moves every surface at once.
 *
 * Three rules stop it from being a background tax: a hidden tab doesn't poll
 * (refreshes when shown again); an untouched page is dropped after
 * `SIDEBAR_POLL_IDLE_LIMIT_MS` until the next touch; each store refuses a tick
 * inside its freshness window or on top of one already out.
 *
 * Reader-provided, so it can't outlive the reader; a logout ends it too.
 */
@Injectable()
export class SidebarCountsPoll {
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly zone = inject(NgZone);
  private readonly apiBase = inject(API_BASE_URL);

  private timerId: ReturnType<typeof setInterval> | null = null;
  private lastActivityAt = Date.now();

  /** The marker value the last tick saw. A tick fetches only when the live
   *  marker differs from this — or when the floor forces it. */
  private lastMarkerToken: string | null = null;

  /** Ticks since the last actual counts fetch. At the floor it fetches
   *  regardless of the marker, then resets. */
  private ticksSinceFetch = 0;

  private readonly onActivity = (): void => {
    const hadGivenUp = this.isIdle();
    this.lastActivityAt = Date.now();
    // Coming back to an abandoned page: the counts are hours old, so catch up
    // now rather than at the end of the next interval.
    if (hadGivenUp) this.resume();
  };

  private readonly onVisibilityChange = (): void => {
    if (document.visibilityState === 'hidden') {
      this.stopTicking();
      return;
    }
    // Turning back to the tab is itself a sign of life, and it must reset the
    // idle clock: a tab hidden for longer than the limit would otherwise be
    // written off the instant it came back.
    this.lastActivityAt = Date.now();
    this.resume();
  };

  constructor() {
    // Everything this service schedules stays OUT of the Angular zone: a
    // `wheel` listener inside it would run change detection on every scroll
    // notch, and an interval would never let the zone go stable. Store
    // reloads step back in for the work that actually changes the screen.
    this.zone.runOutsideAngular(() => {
      for (const event of ACTIVITY_EVENTS) {
        document.addEventListener(event, this.onActivity, { passive: true });
      }
      document.addEventListener('visibilitychange', this.onVisibilityChange);
    });
    // No reload here: the reader's own boot load owns the first fetch, and a
    // tick racing it would stamp the freshness clock against it. Record the
    // marker the boot loads against, so the first tick can tell what's moved
    // (outside the zone, like every request here, so `whenStable()` never hangs).
    this.zone.runOutsideAngular(() => void this.refreshMarkerBaseline());
    this.startTicking();

    onIdentityChange(() => this.stopTicking());
    inject(DestroyRef).onDestroy(() => this.stop());
  }

  private stop(): void {
    this.stopTicking();
    for (const event of ACTIVITY_EVENTS) {
      document.removeEventListener(event, this.onActivity);
    }
    document.removeEventListener('visibilitychange', this.onVisibilityChange);
  }

  /** Bring the counts fully up to date now, then keep them there. A regain or a
   *  wake is when a change made elsewhere is most likely waiting, so this takes
   *  the full list rather than the cheap counts path. */
  private resume(): void {
    this.reloadFully();
    this.startTicking();
  }

  private async tick(): Promise<void> {
    // Both give-up conditions are read here rather than trusted to the handlers
    // that set them: `visibilitychange` does not fire in every teardown path,
    // and nothing at all fires when a page simply goes quiet.
    if (this.givenUp()) {
      this.stopTicking();
      return;
    }
    const token = await this.readMarker();
    // The marker read is a round trip; the tab may have been hidden or gone
    // idle while it was out.
    if (this.givenUp()) {
      this.stopTicking();
      return;
    }
    const floorReached = ++this.ticksSinceFetch >= SIDEBAR_POLL_MARKER_FLOOR_TICKS;
    // A missing or unreadable marker reads as "moved", which falls back to
    // fetching every tick — exactly the behaviour before the marker existed.
    const moved = token === null || token !== this.lastMarkerToken;
    if (token !== null) this.lastMarkerToken = token;
    if (!moved && !floorReached) return;
    this.refreshCounts();
  }

  /** The cheap steady tick: counts only, no feeds or tags. */
  private refreshCounts(): void {
    this.ticksSinceFetch = 0;
    this.zone.run(() => {
      this.subscriptions.reloadCountsIfStale();
      this.savedSearches.reloadIfStale();
    });
  }

  /** The full reload behind a regain or a wake: the whole subscription list,
   *  so a structural change made elsewhere lands. Refreshes the marker baseline
   *  after it, so the next steady tick does not re-fetch what this just took. */
  private reloadFully(): void {
    this.ticksSinceFetch = 0;
    this.zone.run(() => {
      this.subscriptions.reloadQuietlyIfStale();
      this.savedSearches.reloadIfStale();
    });
    void this.refreshMarkerBaseline();
  }

  private async refreshMarkerBaseline(): Promise<void> {
    const token = await this.readMarker();
    if (token !== null) this.lastMarkerToken = token;
  }

  /** Read the marker OUTSIDE `HttpClient`: `authInterceptor` would attach a
   *  bearer token to this same-origin, no-auth-needed file. `no-cache` forces
   *  revalidation (a `304` costs bytes, not PHP). Any failure falls back to fetching. */
  private async readMarker(): Promise<string | null> {
    try {
      const response = await fetch(`${this.apiBase}${CHANGE_MARKER_PATH}`, { cache: 'no-cache' });
      if (!response.ok) return null;
      return (await response.text()).trim();
    } catch {
      return null;
    }
  }

  private givenUp(): boolean {
    return this.isIdle() || document.visibilityState === 'hidden';
  }

  private isIdle(): boolean {
    return Date.now() - this.lastActivityAt >= SIDEBAR_POLL_IDLE_LIMIT_MS;
  }

  private startTicking(): void {
    if (this.timerId !== null) return;
    this.zone.runOutsideAngular(() => {
      this.timerId = setInterval(() => void this.tick(), SIDEBAR_RELOAD_INTERVAL_MS);
    });
  }

  private stopTicking(): void {
    if (this.timerId === null) return;
    clearInterval(this.timerId);
    this.timerId = null;
  }
}
