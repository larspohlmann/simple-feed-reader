import { WritableSignal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ListAutoplayService } from './list-autoplay.service';
import { UserDeviceStorage } from './user-device-storage';
import { provideAccountIdentity } from '../../../testing/account-identity-testing';

describe('ListAutoplayService', () => {
  let userId: WritableSignal<number | null>;
  let realMatchMedia: typeof globalThis.matchMedia | undefined;

  beforeEach(() => {
    localStorage.clear();
    realMatchMedia = globalThis.matchMedia;
    userId = signal<number | null>(7);
    TestBed.configureTestingModule({ providers: [provideAccountIdentity(userId)] });
  });

  afterEach(() => {
    globalThis.matchMedia = realMatchMedia as typeof globalThis.matchMedia;
  });

  function reduceMotion(reduce: boolean) {
    globalThis.matchMedia = ((query: string) => ({
      matches: reduce && query.includes('prefers-reduced-motion'),
    })) as unknown as typeof globalThis.matchMedia;
  }

  it('autoplays by default', () => {
    reduceMotion(false);
    expect(TestBed.inject(ListAutoplayService).enabled()).toBe(true);
  });

  it('does not autoplay by default when the system asks for reduced motion', () => {
    reduceMotion(true);
    expect(TestBed.inject(ListAutoplayService).enabled()).toBe(false);
  });

  it('lets a stored choice beat the reduced-motion default', () => {
    reduceMotion(true);
    const service = TestBed.inject(ListAutoplayService);

    service.setEnabled(true);

    expect(service.enabled()).toBe(true);
    expect(localStorage.getItem('sfr.user.7.listAutoplay')).toBe('on');
  });

  it('stores a switched-off choice for this account on this device', () => {
    reduceMotion(false);
    const service = TestBed.inject(ListAutoplayService);

    service.setEnabled(false);

    expect(service.enabled()).toBe(false);
    expect(localStorage.getItem('sfr.user.7.listAutoplay')).toBe('off');
  });

  it('falls back to the default once the account forgets its device values', () => {
    reduceMotion(false);
    const service = TestBed.inject(ListAutoplayService);
    service.setEnabled(false);

    TestBed.inject(UserDeviceStorage).forgetCurrentUser();

    expect(service.enabled()).toBe(true);
  });
});
