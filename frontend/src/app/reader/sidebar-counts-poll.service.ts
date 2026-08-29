// src/app/reader/sidebar-counts-poll.service.ts
import { DestroyRef, Injectable, NgZone, inject } from '@angular/core';
import { onIdentityChange } from '../core/session-identity';
import { SavedSearchesStore } from './saved-searches.store';
import { SIDEBAR_RELOAD_INTERVAL_MS } from './sidebar-freshness';
import { SubscriptionsStore } from './subscriptions.store';

/** How long a page may sit untouched before the poll gives up on it. The tab
 *  visibility rule already covers a backgrounded tab; this covers the other
 *  abandonment, a reader left open and visible on a screen nobody is at. Two
 *  hours outlasts a meeting or a lunch break and still stops an overnight
 *  machine well before morning. */
export const SIDEBAR_POLL_IDLE_LIMIT_MS = 2 * 60 * 60 * 1000;

/** What counts as "somebody is still here". `pointerdown` covers mouse, touch
 *  and pen; `wheel` covers the reader who only scrolls; `keydown` covers the
 *  one who only uses the keyboard. Deliberately not `pointermove`: a nudged
 *  desk would keep an abandoned tab polling forever. */
const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel'] as const;

/**
 * Keeps the sidebar counts moving on their own while the reader is open (#708).
 *
 * The counts used to change only when something else asked for them — a boot, a
 * subscribe, a refresh, a selection change. A reader that stayed on one list
 * showed the numbers from the moment it last navigated.
 *
 * This owns one timer and nothing else. It reloads the two stores that hold
 * every count; the sidebar badges, the list heading and the tab title all read
 * those stores (#709), so one tick moves every surface at once and none of them
 * can drift from another.
 *
 * Three rules stop it from being a background tax:
 *   - a hidden tab does not poll, and refreshes the moment it is shown again;
 *   - a page nobody has touched for `SIDEBAR_POLL_IDLE_LIMIT_MS` is dropped
 *     until the next touch;
 *   - each store refuses a tick inside its freshness window or on top of a
 *     request it already has out, so a tick costs nothing it need not.
 *
 * The reader provides it, so it cannot outlive the reader; a logout ends it
 * too, since the next user's counts are not this poll's business.
 */
@Injectable()
export class SidebarCountsPoll {
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly zone = inject(NgZone);

  private timerId: ReturnType<typeof setInterval> | null = null;
  private lastActivityAt = Date.now();

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
    // Everything this service schedules stays OUT of the Angular zone. A
    // `wheel` listener inside it would run change detection on every notch of
    // every scroll, and an interval inside it never lets the zone go stable —
    // which hangs anything that waits for the app to settle. `refresh()` steps
    // back in for the work that actually changes what is on screen.
    this.zone.runOutsideAngular(() => {
      for (const event of ACTIVITY_EVENTS) {
        document.addEventListener(event, this.onActivity, { passive: true });
      }
      document.addEventListener('visibilitychange', this.onVisibilityChange);
    });
    // No refresh here. The reader's own boot load owns the first fetch, and a
    // tick racing it would stamp the freshness clock against it.
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

  /** Bring the counts up to date now, then keep them there. */
  private resume(): void {
    this.refresh();
    this.startTicking();
  }

  private tick(): void {
    // Both give-up conditions are read here rather than trusted to the handlers
    // that set them: `visibilitychange` does not fire in every teardown path,
    // and nothing at all fires when a page simply goes quiet.
    if (this.isIdle() || document.visibilityState === 'hidden') {
      this.stopTicking();
      return;
    }
    this.refresh();
  }

  private refresh(): void {
    this.zone.run(() => {
      this.subscriptions.reloadQuietlyIfStale();
      this.savedSearches.reloadIfStale();
    });
  }

  private isIdle(): boolean {
    return Date.now() - this.lastActivityAt >= SIDEBAR_POLL_IDLE_LIMIT_MS;
  }

  private startTicking(): void {
    if (this.timerId !== null) return;
    this.zone.runOutsideAngular(() => {
      this.timerId = setInterval(() => this.tick(), SIDEBAR_RELOAD_INTERVAL_MS);
    });
  }

  private stopTicking(): void {
    if (this.timerId === null) return;
    clearInterval(this.timerId);
    this.timerId = null;
  }
}
