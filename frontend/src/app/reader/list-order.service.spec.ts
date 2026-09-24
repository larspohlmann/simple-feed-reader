import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AccountIdentity } from '../core/account-identity';
import { ListOrderService } from './list-order.service';
import { Selection } from './query';
import { UnreadFilterService } from './unread-filter.service';

describe('ListOrderService', () => {
  const tag3: Selection = { kind: 'tag', id: 3, unread: false };
  const tag4: Selection = { kind: 'tag', id: 4, unread: false };
  const stored = () => localStorage.getItem('sfr.user.5.oldest-first-views');
  const service = () => TestBed.inject(ListOrderService);

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [{ provide: AccountIdentity, useValue: { userId: signal(5) } }],
    });
  });

  it('starts every list newest first', () => {
    expect(service().orderFor(tag3)).toBe('newest');
  });

  it('remembers oldest first for the one list it was set on', () => {
    service().set(tag3, 'oldest');
    expect(service().orderFor(tag3)).toBe('oldest');
    expect(service().orderFor(tag4)).toBe('newest');
    expect(JSON.parse(stored()!)).toEqual(['tag:3']);
  });

  it('keeps the other lists when one flips back', () => {
    service().set(tag3, 'oldest');
    service().set(tag4, 'oldest');
    service().set(tag3, 'newest');
    expect(JSON.parse(stored()!)).toEqual(['tag:4']);
  });

  it('drops the stored value once no list is oldest first', () => {
    service().set(tag3, 'oldest');
    service().set(tag3, 'newest');
    expect(stored()).toBeNull();
  });

  it('shares one order across every direct search term', () => {
    service().set({ kind: 'search', id: null, unread: false, term: 'angular' }, 'oldest');
    expect(service().orderFor({ kind: 'search', id: null, unread: false, term: 'react' })).toBe(
      'oldest',
    );
  });

  it('never stores an order for for you', () => {
    service().set({ kind: 'for-you', id: null, unread: false }, 'oldest');
    expect(localStorage.length).toBe(0);
  });

  it('reads an unparseable stored value as no oldest-first list', () => {
    localStorage.setItem('sfr.user.5.oldest-first-views', '{nope');
    expect(service().orderFor(tag3)).toBe('newest');
  });

  it('reads a stored value that is no list as no oldest-first list', () => {
    localStorage.setItem('sfr.user.5.oldest-first-views', '"tag:3"');
    expect(service().orderFor(tag3)).toBe('newest');
  });

  it('ignores stored entries that are not keys', () => {
    localStorage.setItem('sfr.user.5.oldest-first-views', '[3, "tag:3"]');
    expect(service().orderFor(tag3)).toBe('oldest');
    expect(service().oldestFirstViews().size).toBe(1);
  });

  it('keeps the same views when another preference of the account is written', () => {
    service().set(tag3, 'oldest');
    const before = service().oldestFirstViews();

    TestBed.inject(UnreadFilterService).set(true);

    expect(service().oldestFirstViews()).toBe(before);
  });
});
