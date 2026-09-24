import { Injectable, computed, inject } from '@angular/core';
import { AuthService } from './auth.service';
import { TokenStore } from './token.store';

/** The signed-in account's id: from the token's claim at once, or from `/api/me`
 *  for a token issued before the claim existed (#1143). */
@Injectable({ providedIn: 'root' })
export class AccountIdentity {
  private readonly tokens = inject(TokenStore);
  private readonly auth = inject(AuthService);

  readonly userId = computed(
    () => userIdClaim(this.tokens.token()) ?? this.auth.user()?.id ?? null,
  );
}

export function userIdClaim(token: string | null): number | null {
  const payload = token?.split('.')[1];
  if (!payload) return null;
  try {
    const claims = JSON.parse(atob(payload.replace(/-/g, '+').replace(/_/g, '/'))) as unknown;
    const userId = (claims as { userId?: unknown } | null)?.userId;
    return typeof userId === 'number' && Number.isInteger(userId) ? userId : null;
  } catch {
    return null;
  }
}
