import { computed, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import { AuthService, CurrentUser } from '../../core/auth/auth.service';
import { FeedDirectoryApi } from './feed-directory-api';
import { FeedDirectoryScope } from '../../core/preferences/feed-directory-scope';
import { FeedDirectoryScopeService } from './feed-directory-scope.service';

function userWith(scope: FeedDirectoryScope | undefined): CurrentUser {
  return { preferences: { feedDirectoryScope: scope } } as unknown as CurrentUser;
}

describe('FeedDirectoryScopeService', () => {
  const user = signal<CurrentUser | null>(null);
  const accountLoadFailed = signal(false);
  let ensureUserLoaded: jest.Mock<Observable<CurrentUser>, []>;
  let saveScope: jest.Mock<Observable<CurrentUser>, [FeedDirectoryScope]>;
  let service: FeedDirectoryScopeService;

  beforeEach(() => {
    user.set(null);
    accountLoadFailed.set(false);
    ensureUserLoaded = jest.fn(() => throwError(() => new Error('offline')));
    saveScope = jest.fn();
    TestBed.configureTestingModule({
      providers: [
        {
          provide: AuthService,
          useValue: {
            user,
            accountResolved: computed(() => user() !== null || accountLoadFailed()),
            adopt: (adopted: CurrentUser) => user.set(adopted),
            ensureUserLoaded,
          },
        },
        { provide: FeedDirectoryApi, useValue: { saveScope } },
      ],
    });
    service = TestBed.inject(FeedDirectoryScopeService);
  });

  it('knows the scope only once the account has arrived', () => {
    expect(service.accountState()).toBe('loading');

    user.set(userWith(undefined));

    expect(service.accountState()).toBe('ready');
  });

  it('knows there is no scope to read when the account failed to load', () => {
    accountLoadFailed.set(true);

    expect(service.accountState()).toBe('failed');
    expect(service.hasScope()).toBe(false);
  });

  it('does not report a failed account once the account has arrived', () => {
    accountLoadFailed.set(true);
    user.set(userWith(undefined));

    expect(service.accountState()).toBe('ready');
  });

  it('asks for the account again on retry, and survives a second failure', () => {
    accountLoadFailed.set(true);

    service.retryAccount();

    expect(ensureUserLoaded).toHaveBeenCalledTimes(1);
  });

  it('reads the scope from the signed-in user', () => {
    user.set(userWith({ countries: ['DE'], languages: ['de'] }));

    expect(service.scope()).toEqual({ countries: ['DE'], languages: ['de'] });
    expect(service.hasScope()).toBe(true);
  });

  it('has no scope when the profile lacks the field', () => {
    user.set(userWith(undefined));

    expect(service.scope()).toEqual({ countries: [], languages: [] });
    expect(service.hasScope()).toBe(false);
  });

  it('has no scope when only one list is filled', () => {
    user.set(userWith({ countries: ['DE'], languages: [] }));

    expect(service.hasScope()).toBe(false);
  });

  it('saves and adopts the returned profile', () => {
    const scope = { countries: ['AT'], languages: ['de'] };
    saveScope.mockReturnValue(of(userWith(scope)));
    let saved: boolean | undefined;

    service.save(scope).subscribe((result) => (saved = result));

    expect(saveScope).toHaveBeenCalledWith(scope);
    expect(saved).toBe(true);
    expect(user()?.preferences.feedDirectoryScope).toEqual(scope);
    expect(service.scope()).toEqual(scope);
  });

  it('resolves false and keeps the user when the save fails', () => {
    const before = userWith({ countries: ['DE'], languages: ['de'] });
    user.set(before);
    saveScope.mockReturnValue(throwError(() => new Error('boom')));
    let saved: boolean | undefined;

    service.save({ countries: ['AT'], languages: ['de'] }).subscribe((result) => (saved = result));

    expect(saved).toBe(false);
    expect(user()).toBe(before);
  });
});
