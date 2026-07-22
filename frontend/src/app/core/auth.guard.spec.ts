// src/app/core/auth.guard.spec.ts
import { TestBed } from '@angular/core/testing';
import { Router, UrlTree } from '@angular/router';
import { TokenStore } from './token.store';
import { authGuard, guestGuard } from './auth.guard';

describe('guards', () => {
  let tokens: TokenStore;

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      providers: [
        {
          provide: Router,
          useValue: {
            createUrlTree: (c: unknown[]) => ({ toString: () => c.join('/') }) as UrlTree,
          },
        },
      ],
    });
    tokens = TestBed.inject(TokenStore);
  });

  const run = (g: typeof authGuard) =>
    TestBed.runInInjectionContext(() => g({} as never, {} as never));

  it('authGuard allows when authenticated, redirects otherwise', () => {
    expect(run(authGuard)).not.toBe(true);
    tokens.set('jwt');
    expect(run(authGuard)).toBe(true);
  });

  it('guestGuard allows when anonymous, redirects when authenticated', () => {
    expect(run(guestGuard)).toBe(true);
    tokens.set('jwt');
    expect(run(guestGuard)).not.toBe(true);
  });
});
