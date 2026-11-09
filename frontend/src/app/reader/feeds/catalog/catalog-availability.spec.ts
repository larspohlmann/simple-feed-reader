import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { AuthService } from '../../../core/auth/auth.service';
import { CatalogAvailability } from './catalog-availability';

describe('CatalogAvailability', () => {
  const user = signal<{ catalog: { hasEntries: boolean } } | null>(null);
  const accountLoadFailed = signal(false);
  let availability: CatalogAvailability;

  beforeEach(() => {
    user.set(null);
    accountLoadFailed.set(false);
    TestBed.configureTestingModule({
      providers: [{ provide: AuthService, useValue: { user, accountLoadFailed } }],
    });
    availability = TestBed.inject(CatalogAvailability);
  });

  it('is unresolved until the account has answered', () => {
    expect(availability.resolved()).toBe(false);
    expect(availability.hasEntries()).toBe(false);
  });

  it('reads the answer off the account', () => {
    user.set({ catalog: { hasEntries: true } });

    expect(availability.resolved()).toBe(true);
    expect(availability.hasEntries()).toBe(true);
  });

  it('resolves a failed account load as nothing to offer', () => {
    accountLoadFailed.set(true);

    expect(availability.resolved()).toBe(true);
    expect(availability.hasEntries()).toBe(false);
    expect(availability.knownEmpty()).toBe(false);
  });

  it('is known empty only once a loaded account says so', () => {
    expect(availability.knownEmpty()).toBe(false);

    user.set({ catalog: { hasEntries: false } });
    expect(availability.knownEmpty()).toBe(true);

    user.set({ catalog: { hasEntries: true } });
    expect(availability.knownEmpty()).toBe(false);
  });
});
