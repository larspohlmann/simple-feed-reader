import { TestBed } from '@angular/core/testing';
import { NavigationEnd, Router, UrlTree } from '@angular/router';
import { Subject } from 'rxjs';
import { ReaderLocationService } from './reader-location.service';
import { TokenStore } from './token.store';
import { authGuard, guestGuard } from './auth.guard';

describe('guards', () => {
  let tokens: TokenStore;
  let events: Subject<unknown>;

  beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
    events = new Subject<unknown>();
    TestBed.configureTestingModule({
      providers: [
        {
          provide: Router,
          useValue: {
            events,
            createUrlTree: (c: unknown[]) => ({ toString: () => c.join('/') }) as UrlTree,
          },
        },
      ],
    });
    tokens = TestBed.inject(TokenStore);
  });

  const run = (g: typeof authGuard, url = '/') =>
    TestBed.runInInjectionContext(() => g({} as never, { url } as never));

  it('authGuard allows when authenticated, redirects otherwise', () => {
    expect(run(authGuard)).not.toBe(true);
    tokens.set('jwt');
    expect(run(authGuard)).toBe(true);
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
