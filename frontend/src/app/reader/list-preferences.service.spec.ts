import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AuthService } from '../core/auth.service';
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
