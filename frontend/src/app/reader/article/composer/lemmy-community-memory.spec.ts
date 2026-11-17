import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { LemmyCommunityMemory } from './lemmy-community-memory';
import { UserDeviceStorage } from '../../../core/preferences/user-device-storage';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';

describe('LemmyCommunityMemory', () => {
  let userId: WritableSignal<number | null>;

  beforeEach(() => {
    localStorage.clear();
    userId = signal<number | null>(7);
    TestBed.configureTestingModule({ providers: [provideAccountIdentity(userId)] });
  });
  afterEach(() => jest.restoreAllMocks());

  it('remembers a community per account, read back by a new instance', () => {
    const memory = TestBed.inject(LemmyCommunityMemory);
    memory.remember(3, 41);
    memory.remember(9, 77);
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ providers: [provideAccountIdentity(userId)] });

    const recalled = TestBed.inject(LemmyCommunityMemory);
    expect(recalled.recall(3)).toBe(41);
    expect(recalled.recall(9)).toBe(77);
  });

  it('keeps a plain value per account under the signed-in user', () => {
    TestBed.inject(LemmyCommunityMemory).remember(3, 41);

    expect(localStorage.getItem('sfr.user.7.lemmyCommunity.3')).toBe('41');
  });

  it('recalls nothing for an unknown account', () => {
    const memory = TestBed.inject(LemmyCommunityMemory);
    memory.remember(3, 41);

    expect(memory.recall(4)).toBeNull();
  });

  it('recalls nothing for another signed-in user', () => {
    const memory = TestBed.inject(LemmyCommunityMemory);
    memory.remember(3, 41);
    userId.set(8);

    expect(memory.recall(3)).toBeNull();
  });

  it.each(['forty', '4.5', ''])('recalls nothing from a stored %p', (stored) => {
    localStorage.setItem('sfr.user.7.lemmyCommunity.3', stored);

    expect(TestBed.inject(LemmyCommunityMemory).recall(3)).toBeNull();
  });

  it('is forgotten with the rest of the user’s device values', () => {
    const memory = TestBed.inject(LemmyCommunityMemory);
    memory.remember(3, 41);

    TestBed.inject(UserDeviceStorage).forgetCurrentUser();

    expect(memory.recall(3)).toBeNull();
  });

  it('neither recalls nor remembers, without throwing, while storage is blocked', () => {
    const blocked = () => {
      throw new DOMException('blocked', 'SecurityError');
    };
    jest.spyOn(Storage.prototype, 'getItem').mockImplementation(blocked);
    jest.spyOn(Storage.prototype, 'setItem').mockImplementation(blocked);
    const memory = TestBed.inject(LemmyCommunityMemory);

    expect(() => memory.remember(3, 41)).not.toThrow();
    expect(memory.recall(3)).toBeNull();
  });
});
