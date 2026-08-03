// src/app/core/session-identity.ts
import { effect, inject, untracked } from '@angular/core';
import { TokenStore } from './token.store';

/**
 * Runs `onChange` whenever the signed-in identity changes.
 *
 * Per-user state held in a `providedIn: 'root'` service outlives a logout.
 * Nothing reloads the page — `AuthService.logout()` navigates with the router,
 * and the interceptor's 401 path only clears the token — so the root injector,
 * and everything cached in it, survives into the next user's session (#263).
 * Binding the reset to the token covers both of those paths, and any later one,
 * without a core service having to know which feature stores exist.
 *
 * The identity present when the caller is created is NOT a change: a reload
 * rebuilds every service against the same stored token, and counting that as a
 * new session would discard state the reload is supposed to keep.
 *
 * Call from an injection context.
 */
export function onIdentityChange(onChange: () => void): void {
  const tokens = inject(TokenStore);
  let identity = tokens.token();

  effect(() => {
    const next = tokens.token();
    if (next === identity) {
      return;
    }
    identity = next;
    untracked(onChange);
  });
}
