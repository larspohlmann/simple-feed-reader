import { TestBed } from '@angular/core/testing';
import { TokenStore } from '../core/token.store';
import { OnboardingSkip } from './onboarding-skip';

describe('OnboardingSkip', () => {
  let tokens: TokenStore;

  /** A fresh injector, so the service reads the token as its starting identity
   *  the same way it does on a page load. */
  const build = (): OnboardingSkip => TestBed.inject(OnboardingSkip);

  beforeEach(() => {
    sessionStorage.clear();
    localStorage.clear();
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});
    tokens = TestBed.inject(TokenStore);
    tokens.set('user-a.jwt');
  });

  it('is not skipped by default', () => {
    expect(build().wasSkipped()).toBe(false);
  });

  it('remembers a skip for the session', () => {
    build().remember();
    expect(build().wasSkipped()).toBe(true);
  });

  it('survives a storage that throws, because a private-mode failure must not break the reader', () => {
    const broken = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('QuotaExceeded');
    });

    expect(() => build().remember()).not.toThrow();
    broken.mockRestore();
  });

  // #263. A skip belongs to the user who skipped it. Without this, the next
  // user to sign in in the same tab is never redirected into the picker.
  it('forgets the skip when the signed-in identity changes', () => {
    const skip = build();
    skip.remember();
    expect(skip.wasSkipped()).toBe(true);

    tokens.clear();
    tokens.set('user-b.jwt');
    TestBed.tick();

    expect(skip.wasSkipped()).toBe(false);
  });

  // The flag is deliberately session-scoped, so a reload has to keep it —
  // otherwise the reader bounces the user back into the picker they just left.
  it('keeps the skip when the identity is unchanged', () => {
    const skip = build();
    skip.remember();

    TestBed.tick();

    expect(skip.wasSkipped()).toBe(true);
  });
});
