import { WritableSignal, computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { AccountIdentity } from './account-identity';
import { UserDeviceStorage } from './user-device-storage';

describe('UserDeviceStorage', () => {
  let userId: WritableSignal<number | null>;
  let storage: UserDeviceStorage;

  beforeEach(() => {
    localStorage.clear();
    userId = signal<number | null>(7);
    TestBed.configureTestingModule({
      providers: [{ provide: AccountIdentity, useValue: { userId } }],
    });
    storage = TestBed.inject(UserDeviceStorage);
  });

  it('keeps each value under the signed-in user', () => {
    storage.write('unread-only', '1');
    expect(localStorage.getItem('sfr.user.7.unread-only')).toBe('1');
    expect(storage.read('unread-only')).toBe('1');
  });

  it("shows one user nothing of another's", () => {
    storage.write('unread-only', '1');
    userId.set(8);
    expect(storage.read('unread-only')).toBeNull();
    userId.set(7);
    expect(storage.read('unread-only')).toBe('1');
  });

  it('removes a value written as null', () => {
    storage.write('unread-only', '1');
    storage.write('unread-only', null);
    expect(localStorage.getItem('sfr.user.7.unread-only')).toBeNull();
  });

  it('neither reads nor writes before the account is known', () => {
    userId.set(null);
    storage.write('unread-only', '1');
    expect(localStorage.length).toBe(0);
    expect(storage.read('unread-only')).toBeNull();
  });

  it('notifies a computed that read it when a value is written', () => {
    const views = computed(() => storage.read('oldest-first-views'));
    expect(views()).toBeNull();
    storage.write('oldest-first-views', '["all"]');
    expect(views()).toBe('["all"]');
  });

  it("forgets every value of the signed-in user and nobody else's", () => {
    storage.write('unread-only', '1');
    storage.write('oldest-first-views', '["all"]');
    localStorage.setItem('sfr.user.70.unread-only', '1');
    localStorage.setItem('sfr.layout', 'list');

    storage.forgetCurrentUser();

    expect(localStorage.getItem('sfr.user.7.unread-only')).toBeNull();
    expect(localStorage.getItem('sfr.user.7.oldest-first-views')).toBeNull();
    expect(localStorage.getItem('sfr.user.70.unread-only')).toBe('1');
    expect(localStorage.getItem('sfr.layout')).toBe('list');
  });
});
