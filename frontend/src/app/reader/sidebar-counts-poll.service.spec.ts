import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../core/api';
import { SavedSearchesStore } from './saved-searches.store';
import {
  SIDEBAR_POLL_IDLE_LIMIT_MS,
  SIDEBAR_POLL_MARKER_FLOOR_TICKS,
  SidebarCountsPoll,
} from './sidebar-counts-poll.service';
import { SIDEBAR_RELOAD_INTERVAL_MS } from './sidebar-freshness';
import { SubscriptionsStore } from './subscriptions.store';

describe('SidebarCountsPoll', () => {
  let reloadCounts: jest.Mock;
  let reloadFull: jest.Mock;
  let reloadBadges: jest.Mock;
  let fetchMock: jest.Mock;

  /** The marker the mocked web server serves. `null` means the file is missing
   *  or unreadable (a 404 / non-ok); `fails` means the request itself threw. */
  let markerToken: string | null;
  let markerFails: boolean;

  const realFetch = globalThis.fetch;

  /** jsdom reports 'visible' and has no way to set it; both halves of the
   *  visibility rule need it writable. */
  function setVisibility(state: DocumentVisibilityState): void {
    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      get: () => state,
    });
    document.dispatchEvent(new Event('visibilitychange'));
  }

  /** How many times the poll refreshed anything. The saved-searches store is
   *  reloaded on every refresh — cheap tick and full reload alike — so its call
   *  count is the total, and the two subscription paths must sum to it. */
  function refreshes(): number {
    expect(reloadCounts.mock.calls.length + reloadFull.mock.calls.length).toBe(
      reloadBadges.mock.calls.length,
    );
    return reloadBadges.mock.calls.length;
  }

  /** Advance fake time AND flush the microtasks each tick's marker read spawns. */
  function elapse(ms: number): Promise<void> {
    return jest.advanceTimersByTimeAsync(ms);
  }

  /** Start the poll, then settle the marker baseline it reads at construction. */
  async function start(token: string | null = null): Promise<void> {
    markerToken = token;
    TestBed.inject(SidebarCountsPoll);
    await jest.advanceTimersByTimeAsync(0);
  }

  beforeEach(() => {
    jest.useFakeTimers({ now: new Date('2026-08-29T16:00:00Z') });
    setVisibility('visible');
    markerToken = null;
    markerFails = false;
    fetchMock = jest.fn(async () => {
      if (markerFails) throw new Error('network down');
      if (markerToken === null) return { ok: false, text: async () => '' } as Response;
      return { ok: true, text: async () => markerToken } as unknown as Response;
    });
    globalThis.fetch = fetchMock as unknown as typeof fetch;
    reloadCounts = jest.fn();
    reloadFull = jest.fn();
    reloadBadges = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        SidebarCountsPoll,
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        {
          provide: SubscriptionsStore,
          useValue: { reloadCountsIfStale: reloadCounts, reloadQuietlyIfStale: reloadFull },
        },
        { provide: SavedSearchesStore, useValue: { reloadIfStale: reloadBadges } },
      ],
    });
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    jest.useRealTimers();
    globalThis.fetch = realFetch;
  });

  describe('the change marker', () => {
    it('reads the marker off disk, not through HttpClient', async () => {
      await start('A');
      await elapse(SIDEBAR_RELOAD_INTERVAL_MS);

      expect(fetchMock).toHaveBeenCalledWith('https://api.test/state/counts.json', {
        cache: 'no-cache',
      });
    });

    it('does nothing while the marker has not moved', async () => {
      await start('A');

      await elapse(SIDEBAR_RELOAD_INTERVAL_MS);

      expect(refreshes()).toBe(0);
    });

    it('runs the cheap counts reload once the marker moves', async () => {
      await start('A');
      markerToken = 'B';

      await elapse(SIDEBAR_RELOAD_INTERVAL_MS);

      expect(reloadCounts).toHaveBeenCalledTimes(1);
      expect(reloadFull).not.toHaveBeenCalled();
    });

    it('falls back to fetching when the marker is missing', async () => {
      await start(null);

      await elapse(SIDEBAR_RELOAD_INTERVAL_MS);

      expect(reloadCounts).toHaveBeenCalledTimes(1);
    });

    it('falls back to fetching when the marker request fails', async () => {
      await start('A');
      markerFails = true;

      await elapse(SIDEBAR_RELOAD_INTERVAL_MS);

      expect(reloadCounts).toHaveBeenCalledTimes(1);
    });

    it('forces a fetch on the floor tick even when nothing moved', async () => {
      await start('A');

      await elapse(SIDEBAR_RELOAD_INTERVAL_MS * (SIDEBAR_POLL_MARKER_FLOOR_TICKS - 1));
      expect(reloadCounts).not.toHaveBeenCalled();

      await elapse(SIDEBAR_RELOAD_INTERVAL_MS);
      expect(reloadCounts).toHaveBeenCalledTimes(1);
    });
  });

  it('reloads on every tick when no marker is present (today’s behaviour)', async () => {
    await start(null);

    await elapse(SIDEBAR_RELOAD_INTERVAL_MS);
    expect(refreshes()).toBe(1);

    await elapse(SIDEBAR_RELOAD_INTERVAL_MS * 3);
    expect(refreshes()).toBe(4);
  });

  it('does not refresh before the first interval has elapsed', async () => {
    // The shell's own boot load owns the first fetch. A tick at zero would race
    // it and leave the store unresolved.
    await start(null);

    await elapse(SIDEBAR_RELOAD_INTERVAL_MS - 1);

    expect(refreshes()).toBe(0);
  });

  it('sends nothing at all while the tab is hidden', async () => {
    await start(null);
    setVisibility('hidden');

    await elapse(SIDEBAR_RELOAD_INTERVAL_MS * 10);

    expect(refreshes()).toBe(0);
  });

  it('does a full reload the moment a hidden tab is shown again', async () => {
    await start(null);
    setVisibility('hidden');
    await elapse(SIDEBAR_RELOAD_INTERVAL_MS * 10);

    setVisibility('visible');

    // A structural change made elsewhere is most likely waiting on regain, so
    // the tab takes the whole list, not the counts-only path.
    expect(reloadFull).toHaveBeenCalledTimes(1);
    expect(refreshes()).toBe(1);
  });

  it('resumes cheap ticking after the tab is shown again', async () => {
    await start(null);
    setVisibility('hidden');
    setVisibility('visible');

    await elapse(SIDEBAR_RELOAD_INTERVAL_MS);

    expect(reloadFull).toHaveBeenCalledTimes(1);
    expect(reloadCounts).toHaveBeenCalledTimes(1);
    expect(refreshes()).toBe(2);
  });

  it('gives up on a page nobody has touched for the idle limit', async () => {
    await start(null);
    await elapse(SIDEBAR_POLL_IDLE_LIMIT_MS);
    const beforeGivingUp = refreshes();

    await elapse(SIDEBAR_RELOAD_INTERVAL_MS * 100);

    expect(refreshes()).toBe(beforeGivingUp);
  });

  it('keeps polling a page the user is still touching', async () => {
    await start(null);
    for (let elapsed = 0; elapsed < SIDEBAR_POLL_IDLE_LIMIT_MS * 2; elapsed += 60_000) {
      await elapse(60_000);
      document.dispatchEvent(new Event('keydown'));
    }

    expect(refreshes()).toBeGreaterThan(SIDEBAR_POLL_IDLE_LIMIT_MS / SIDEBAR_RELOAD_INTERVAL_MS);
  });

  it('wakes with a full reload on the first touch after it has given up', async () => {
    await start(null);
    await elapse(SIDEBAR_POLL_IDLE_LIMIT_MS + SIDEBAR_RELOAD_INTERVAL_MS * 10);
    const whileAsleep = refreshes();

    document.dispatchEvent(new Event('pointerdown'));
    expect(reloadFull).toHaveBeenCalledTimes(1);
    expect(refreshes()).toBe(whileAsleep + 1);

    await elapse(SIDEBAR_RELOAD_INTERVAL_MS);
    expect(refreshes()).toBe(whileAsleep + 2);
  });

  it('treats a wheel as activity, so long reading does not look idle', async () => {
    await start(null);
    await elapse(SIDEBAR_POLL_IDLE_LIMIT_MS - 60_000);
    document.dispatchEvent(new Event('wheel'));
    const beforeTheOldLimit = refreshes();

    await elapse(60_000 + SIDEBAR_RELOAD_INTERVAL_MS);

    expect(refreshes()).toBeGreaterThan(beforeTheOldLimit);
  });

  it('stops for good when the reader that owns it is destroyed', async () => {
    await start(null);
    await elapse(SIDEBAR_RELOAD_INTERVAL_MS);
    const beforeDestroy = refreshes();

    TestBed.resetTestingModule();

    await elapse(SIDEBAR_RELOAD_INTERVAL_MS * 10);
    document.dispatchEvent(new Event('pointerdown'));
    setVisibility('hidden');
    setVisibility('visible');
    expect(refreshes()).toBe(beforeDestroy);
  });
});
