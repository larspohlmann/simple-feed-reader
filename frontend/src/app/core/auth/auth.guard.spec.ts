import { TestBed } from '@angular/core/testing';
import { NavigationEnd, Router, UrlTree } from '@angular/router';
import { Subject, of, throwError } from 'rxjs';
import { AuthService } from './auth.service';
import { ReaderLocationService } from './reader-location.service';
import { TokenStore } from './token.store';
import { authGuard, guestGuard } from './auth.guard';

describe('guards', () => {
  let tokens: TokenStore;
  let events: Subject<unknown>;
  let ensureUserLoaded: jest.Mock;

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    events = new Subject<unknown>();
    ensureUserLoaded = jest.fn(() => of({}));
    TestBed.configureTestingModule({
      providers: [
        { provide: AuthService, useValue: { ensureUserLoaded: () => ensureUserLoaded() } },
        {
          provide: Router,
          useValue: {
            events,
            createUrlTree: (segments: unknown[]) =>
              ({ toString: () => segments.join('/') }) as UrlTree,
          },
        },
      ],
    });
    tokens = TestBed.inject(TokenStore);
  });

  const run = (guard: typeof authGuard, url = '/') =>
    TestBed.runInInjectionContext(() => guard({} as never, { url } as never));

  it('authGuard allows when authenticated, redirects otherwise', () => {
    expect(run(authGuard)).not.toBe(true);
    tokens.set('jwt');
    expect(run(authGuard)).toBe(true);
  });

  it('starts the user load for a signed-in route without waiting for it', () => {
    tokens.set('jwt');
    const pending = new Subject<never>();
    ensureUserLoaded.mockReturnValue(pending);

    expect(run(authGuard, '/settings/admin/about')).toBe(true);
    expect(ensureUserLoaded).toHaveBeenCalledTimes(1);
    expect(pending.observed).toBe(true);
  });

  it('still lets the route render when /api/me fails', () => {
    tokens.set('jwt');
    ensureUserLoaded.mockReturnValue(throwError(() => new Error('offline')));

    expect(run(authGuard)).toBe(true);
  });

  it('loads no user for an anonymous request', () => {
    run(authGuard);
    expect(ensureUserLoaded).not.toHaveBeenCalled();
  });

  it('remembers an anonymous reader request before it redirects to login', () => {
    expect(run(authGuard, '/?tag=17&entry=42-example#comments')).not.toBe(true);

    const location = TestBed.inject(ReaderLocationService);
    expect(location.savedReaderUrl()).toBe('/?tag=17&entry=42-example#comments');
    expect(location.consumeSignInReturnUrl()).toBe('/?tag=17&entry=42-example#comments');
  });

  it('does not remember an anonymous request for another protected route', () => {
    expect(run(authGuard, '/settings/organise')).not.toBe(true);

    const location = TestBed.inject(ReaderLocationService);
    expect(location.savedReaderUrl()).toBe('/');
    expect(location.consumeSignInReturnUrl()).toBe('/');
  });

  it('starts reader navigation tracking when it runs for an authenticated request', () => {
    tokens.set('jwt');
    expect(run(authGuard, '/settings')).toBe(true);

    events.next(new NavigationEnd(1, '/?tag=17', '/?tag=17&entry=42-example#comments'));

    expect(TestBed.inject(ReaderLocationService).savedReaderUrl()).toBe(
      '/?tag=17&entry=42-example#comments',
    );
  });

  it('guestGuard allows when anonymous, redirects when authenticated', () => {
    expect(run(guestGuard)).toBe(true);
    tokens.set('jwt');
    expect(run(guestGuard)).not.toBe(true);
  });
});
