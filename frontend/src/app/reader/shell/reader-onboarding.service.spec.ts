import { TestBed } from '@angular/core/testing';
import { computed, signal } from '@angular/core';
import { Router } from '@angular/router';
import { AuthService } from '../../core/auth/auth.service';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { RefreshService } from '../state/refresh.service';
import { OnboardingSkip } from '../feeds/catalog/onboarding-skip';
import { ReaderOnboarding } from './reader-onboarding.service';

interface Account {
  roles: string[];
  catalog: { hasEntries: boolean };
}

describe('ReaderOnboarding', () => {
  const navigate = jest.fn().mockResolvedValue(true);
  const user = signal<Account | null>(null);
  const accountLoadFailed = signal(false);
  const auth = {
    user,
    accountLoadFailed,
    accountResolved: computed(() => user() !== null || accountLoadFailed()),
    isAdmin: () => user()?.roles.includes('ROLE_ADMIN') ?? false,
  };
  const subscriptions = {
    resolved: signal(true),
    error: signal<unknown>(null),
    subscriptions: signal<{ lastFetchedAt: string | null }[]>([]),
  };
  const refresh = { run: jest.fn(), running: signal(false), failure: signal(null) };

  const reader = (hasEntries: boolean): Account => ({
    roles: ['ROLE_USER'],
    catalog: { hasEntries },
  });
  const admin = (hasEntries: boolean): Account => ({
    roles: ['ROLE_ADMIN'],
    catalog: { hasEntries },
  });

  function start(): ReaderOnboarding {
    const onboarding = TestBed.inject(ReaderOnboarding);
    TestBed.tick();
    return onboarding;
  }

  beforeEach(() => {
    sessionStorage.clear();
    navigate.mockClear();
    user.set(null);
    accountLoadFailed.set(false);
    subscriptions.resolved.set(true);
    subscriptions.error.set(null);
    subscriptions.subscriptions.set([]);
    TestBed.configureTestingModule({
      providers: [
        ReaderOnboarding,
        { provide: Router, useValue: { navigate } },
        { provide: AuthService, useValue: auth },
        { provide: SubscriptionsStore, useValue: subscriptions },
        { provide: RefreshService, useValue: refresh },
      ],
    });
  });

  it('holds an empty reader open until the account answers, then sends it to the picker', () => {
    const onboarding = start();
    expect(onboarding.running()).toBe(true);
    expect(navigate).not.toHaveBeenCalled();

    user.set(reader(true));
    TestBed.tick();

    expect(navigate).toHaveBeenCalledWith(['/discover'], { replaceUrl: true });
  });

  it('leaves an empty reader alone when the catalog offers nothing', () => {
    user.set(reader(false));
    const onboarding = start();

    expect(onboarding.running()).toBe(false);
    expect(navigate).not.toHaveBeenCalled();
  });

  it('does not redirect when the account cannot be loaded', () => {
    accountLoadFailed.set(true);
    const onboarding = start();

    expect(onboarding.running()).toBe(false);
    expect(onboarding.showCatalogEmptyWarning()).toBe(false);
    expect(navigate).not.toHaveBeenCalled();
  });

  it('does not redirect a reader who skipped the picker this session', () => {
    TestBed.inject(OnboardingSkip).remember();
    user.set(reader(true));
    start();

    expect(navigate).not.toHaveBeenCalled();
  });

  it('warns an admin, and only an admin, about an empty catalog', () => {
    subscriptions.subscriptions.set([{ lastFetchedAt: '2026-07-26T10:00:00+00:00' }]);
    user.set(admin(false));
    const onboarding = start();
    expect(onboarding.showCatalogEmptyWarning()).toBe(true);

    user.set(admin(true));
    expect(onboarding.showCatalogEmptyWarning()).toBe(false);

    user.set(reader(false));
    expect(onboarding.showCatalogEmptyWarning()).toBe(false);
  });
});
