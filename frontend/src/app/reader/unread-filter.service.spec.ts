import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { UnreadFilterService } from './unread-filter.service';
import { provideAccountIdentity } from '../../testing/account-identity-testing';

describe('UnreadFilterService', () => {
  let userId: WritableSignal<number | null>;
  const filter = () => TestBed.inject(UnreadFilterService);

  beforeEach(() => {
    localStorage.clear();
    userId = signal<number | null>(3);
    TestBed.configureTestingModule({
      providers: [provideAccountIdentity(userId)],
    });
  });

  it('shows everything when nothing is stored', () => {
    expect(filter().unreadOnly()).toBe(false);
  });

  it("reads the account's stored unread-only choice back", () => {
    localStorage.setItem('sfr.user.3.unread-only', '1');
    expect(filter().unreadOnly()).toBe(true);
  });

  it('stores unread-only under the account and forgets it again for all posts', () => {
    const service = filter();
    service.set(true);
    expect(localStorage.getItem('sfr.user.3.unread-only')).toBe('1');
    expect(service.unreadOnly()).toBe(true);

    service.set(false);
    expect(localStorage.getItem('sfr.user.3.unread-only')).toBeNull();
    expect(service.unreadOnly()).toBe(false);
  });

  it("keeps one account's choice from the next", () => {
    filter().set(true);
    userId.set(4);
    expect(filter().unreadOnly()).toBe(false);
  });

  it('drops the old device-wide value instead of adopting it', () => {
    localStorage.setItem('sfr.unread-only', '1');
    expect(filter().unreadOnly()).toBe(false);
    expect(localStorage.getItem('sfr.unread-only')).toBeNull();
  });

  it('reads a garbage stored value as show-all', () => {
    localStorage.setItem('sfr.user.3.unread-only', 'yes');
    expect(filter().unreadOnly()).toBe(false);
  });
});
