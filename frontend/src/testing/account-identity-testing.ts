import { WritableSignal, computed, signal } from '@angular/core';
import { AccountIdentity } from '../app/core/account-identity';

/** An `AccountIdentity` whose account is whatever the spec says; settled once known. */
export function provideAccountIdentity(userId: WritableSignal<number | null> = signal(1)) {
  return {
    provide: AccountIdentity,
    useValue: { userId, settled: computed(() => userId() !== null) },
  };
}
