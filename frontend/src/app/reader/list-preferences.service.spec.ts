import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AccountIdentity } from '../core/account-identity';
import { ListOrderService } from './list-order.service';
import { ListPreferences } from './list-preferences.service';
import { UnreadFilterService } from './unread-filter.service';

describe('ListPreferences', () => {
  let userId: WritableSignal<number | null>;

  beforeEach(() => {
    localStorage.clear();
    userId = signal<number | null>(2);
    TestBed.configureTestingModule({
      providers: [{ provide: AccountIdentity, useValue: { userId } }],
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
    userId.set(null);
    expect(preferences.ready()).toBe(false);
  });
});
