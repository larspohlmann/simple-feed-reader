import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AuthService } from '../../core/auth/auth.service';
import { ListOrderService } from './list-order.service';
import { ListPreferences } from './list-preferences.service';
import { UnreadFilterService } from './unread-filter.service';

describe('ListPreferences', () => {
  let user: WritableSignal<{ id: number } | null>;
  let accountLoadFailed: WritableSignal<boolean>;

  beforeEach(() => {
    localStorage.clear();
    user = signal<{ id: number } | null>({ id: 2 });
    accountLoadFailed = signal(false);
    TestBed.configureTestingModule({
      providers: [{ provide: AuthService, useValue: { user, accountLoadFailed } }],
    });
  });

  it("applies the account's unread filter and this list's order to a parsed selection", () => {
    TestBed.inject(UnreadFilterService).set(true);
    TestBed.inject(ListOrderService).set({ kind: 'tag', id: 9, unread: false }, 'oldest');

    const applied = TestBed.inject(ListPreferences).appliedTo({
      kind: 'tag',
      id: 9,
      unread: false,
    });

    expect(applied).toEqual({ kind: 'tag', id: 9, unread: true, order: 'oldest' });
  });

  describe('a direct search (#1922)', () => {
    const search = { kind: 'search', id: null, unread: false, term: 'angular' } as const;

    it("opens on all posts, whatever the account's filter says", () => {
      TestBed.inject(UnreadFilterService).set(true);

      expect(TestBed.inject(ListPreferences).appliedTo(search).unread).toBe(false);
    });

    it("keeps a flip to the search and away from the account's filter", () => {
      const preferences = TestBed.inject(ListPreferences);

      preferences.setUnreadOnly(search, true);

      expect(preferences.appliedTo(search).unread).toBe(true);
      expect(TestBed.inject(UnreadFilterService).unreadOnly()).toBe(false);
      expect(preferences.appliedTo({ kind: 'all', id: null, unread: false }).unread).toBe(false);
    });

    it('forgets the flip once the search ends', () => {
      const preferences = TestBed.inject(ListPreferences);
      preferences.setUnreadOnly(search, true);

      preferences.endSearch();

      expect(preferences.appliedTo(search).unread).toBe(false);
    });

    it('reports a flip inside a search as a preference change, and its end as none', () => {
      const preferences = TestBed.inject(ListPreferences);
      const before = preferences.values();

      preferences.setUnreadOnly(search, true);
      const flipped = preferences.values();
      preferences.endSearch();

      expect(flipped).not.toEqual(before);
      expect(preferences.values()).toEqual(flipped);
    });
  });

  it("writes a flip on any other list to the account's filter", () => {
    TestBed.inject(ListPreferences).setUnreadOnly({ kind: 'tag', id: 9, unread: false }, true);

    expect(TestBed.inject(UnreadFilterService).unreadOnly()).toBe(true);
  });

  it('is ready only once the account is known', () => {
    const preferences = TestBed.inject(ListPreferences);
    expect(preferences.ready()).toBe(true);
    user.set(null);
    expect(preferences.ready()).toBe(false);
  });

  it('is ready with the defaults when the account never loads', () => {
    user.set(null);
    accountLoadFailed.set(true);

    const preferences = TestBed.inject(ListPreferences);

    expect(preferences.ready()).toBe(true);
    expect(preferences.appliedTo({ kind: 'tag', id: 9, unread: false })).toEqual({
      kind: 'tag',
      id: 9,
      unread: false,
    });
  });
});
