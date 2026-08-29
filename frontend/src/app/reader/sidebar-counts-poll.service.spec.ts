import { TestBed } from '@angular/core/testing';
import { SavedSearchesStore } from './saved-searches.store';
import { SIDEBAR_POLL_IDLE_LIMIT_MS, SidebarCountsPoll } from './sidebar-counts-poll.service';
import { SIDEBAR_RELOAD_INTERVAL_MS } from './sidebar-freshness';
import { SubscriptionsStore } from './subscriptions.store';

describe('SidebarCountsPoll', () => {
  let reloadCounts: jest.Mock;
  let reloadBadges: jest.Mock;

  /** jsdom reports 'visible' and has no way to set it; both halves of the
   *  visibility rule need it writable. */
  function setVisibility(state: DocumentVisibilityState): void {
    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      get: () => state,
    });
    document.dispatchEvent(new Event('visibilitychange'));
  }

  /** How many ticks both stores have been asked for. They move together, so one
   *  number reads better than two identical assertions everywhere. */
  function refreshes(): number {
    expect(reloadBadges).toHaveBeenCalledTimes(reloadCounts.mock.calls.length);
    return reloadCounts.mock.calls.length;
  }

  beforeEach(() => {
    jest.useFakeTimers({ now: new Date('2026-08-29T16:00:00Z') });
    setVisibility('visible');
    reloadCounts = jest.fn();
    reloadBadges = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        SidebarCountsPoll,
        { provide: SubscriptionsStore, useValue: { reloadQuietlyIfStale: reloadCounts } },
        { provide: SavedSearchesStore, useValue: { reloadIfStale: reloadBadges } },
      ],
    });
    // Injecting it starts it: the reader owns the poll by holding it.
    TestBed.inject(SidebarCountsPoll);
  });

  afterEach(() => {
    TestBed.resetTestingModule();
    jest.useRealTimers();
  });

  it('refreshes both stores on every tick', () => {
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
    expect(refreshes()).toBe(1);

    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS * 3);
    expect(refreshes()).toBe(4);
  });

  it('does not refresh before the first interval has elapsed', () => {
    // The shell's own boot load owns the first fetch. A tick at zero would race
    // it and leave the store unresolved.
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS - 1);
    expect(refreshes()).toBe(0);
  });

  it('sends nothing at all while the tab is hidden', () => {
    setVisibility('hidden');

    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS * 10);

    expect(refreshes()).toBe(0);
  });

  it('refreshes at once when a hidden tab is shown again', () => {
    setVisibility('hidden');
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS * 10);

    setVisibility('visible');

    expect(refreshes()).toBe(1);
  });

  it('resumes ticking after the tab is shown again', () => {
    setVisibility('hidden');
    setVisibility('visible');

    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);

    expect(refreshes()).toBe(2);
  });

  it('gives up on a page nobody has touched for the idle limit', () => {
    jest.advanceTimersByTime(SIDEBAR_POLL_IDLE_LIMIT_MS);
    const beforeGivingUp = refreshes();

    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS * 100);

    expect(refreshes()).toBe(beforeGivingUp);
  });

  it('keeps polling a page the user is still touching', () => {
    for (let elapsed = 0; elapsed < SIDEBAR_POLL_IDLE_LIMIT_MS * 2; elapsed += 60_000) {
      jest.advanceTimersByTime(60_000);
      document.dispatchEvent(new Event('keydown'));
    }

    expect(refreshes()).toBeGreaterThan(SIDEBAR_POLL_IDLE_LIMIT_MS / SIDEBAR_RELOAD_INTERVAL_MS);
  });

  it('wakes on the first touch after it has given up, and refreshes at once', () => {
    jest.advanceTimersByTime(SIDEBAR_POLL_IDLE_LIMIT_MS + SIDEBAR_RELOAD_INTERVAL_MS * 10);
    const whileAsleep = refreshes();

    document.dispatchEvent(new Event('pointerdown'));
    expect(refreshes()).toBe(whileAsleep + 1);

    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
    expect(refreshes()).toBe(whileAsleep + 2);
  });

  it('treats a wheel as activity, so long reading does not look idle', () => {
    jest.advanceTimersByTime(SIDEBAR_POLL_IDLE_LIMIT_MS - 60_000);
    document.dispatchEvent(new Event('wheel'));
    const beforeTheOldLimit = refreshes();

    jest.advanceTimersByTime(60_000 + SIDEBAR_RELOAD_INTERVAL_MS);

    expect(refreshes()).toBeGreaterThan(beforeTheOldLimit);
  });

  it('stops for good when the reader that owns it is destroyed', () => {
    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS);
    const beforeDestroy = refreshes();

    TestBed.resetTestingModule();

    jest.advanceTimersByTime(SIDEBAR_RELOAD_INTERVAL_MS * 10);
    document.dispatchEvent(new Event('pointerdown'));
    setVisibility('hidden');
    setVisibility('visible');
    expect(refreshes()).toBe(beforeDestroy);
  });
});
