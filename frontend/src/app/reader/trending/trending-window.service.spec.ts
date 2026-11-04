import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideAccountIdentity } from '../../../testing/account-identity-testing';
import { TrendingWindowService } from './trending-window.service';

describe('TrendingWindowService', () => {
  let userId: WritableSignal<number | null>;
  const service = () => TestBed.inject(TrendingWindowService);

  beforeEach(() => {
    localStorage.clear();
    userId = signal<number | null>(3);
    TestBed.configureTestingModule({ providers: [provideAccountIdentity(userId)] });
  });

  it('starts on the past 24 hours', () => {
    expect(service().window()).toBe('day');
  });

  it("reads the account's stored week back", () => {
    localStorage.setItem('sfr.user.3.trending-window', 'week');
    expect(service().window()).toBe('week');
  });

  it('stores the week under the account and forgets it again for the day', () => {
    service().set('week');
    expect(localStorage.getItem('sfr.user.3.trending-window')).toBe('week');
    expect(service().window()).toBe('week');

    service().set('day');
    expect(localStorage.getItem('sfr.user.3.trending-window')).toBeNull();
    expect(service().window()).toBe('day');
  });

  it("keeps one account's window from the next", () => {
    service().set('week');
    userId.set(4);
    expect(service().window()).toBe('day');
  });

  it('reads a garbage stored value as the day', () => {
    localStorage.setItem('sfr.user.3.trending-window', 'month');
    expect(service().window()).toBe('day');
  });
});
