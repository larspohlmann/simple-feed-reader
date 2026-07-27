import { OnboardingSkip } from './onboarding-skip';

describe('OnboardingSkip', () => {
  beforeEach(() => sessionStorage.clear());

  it('is not skipped by default', () => {
    expect(new OnboardingSkip().wasSkipped()).toBe(false);
  });

  it('remembers a skip for the session', () => {
    const skip = new OnboardingSkip();
    skip.remember();
    expect(new OnboardingSkip().wasSkipped()).toBe(true);
  });

  it('survives a storage that throws, because a private-mode failure must not break the reader', () => {
    const broken = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
      throw new Error('QuotaExceeded');
    });

    expect(() => new OnboardingSkip().remember()).not.toThrow();
    broken.mockRestore();
  });
});
