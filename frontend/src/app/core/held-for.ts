import { Signal, effect, signal } from '@angular/core';

/** True once `source` has stayed true for `delayMs`; false the moment it drops.
 *  Runs in an injection context (a field initializer, a constructor), as the
 *  effect behind it does. */
export function heldFor(source: () => boolean, delayMs: number): Signal<boolean> {
  const held = signal(false);
  effect((onCleanup) => {
    if (!source()) {
      held.set(false);
      return;
    }
    const timer = setTimeout(() => held.set(true), delayMs);
    onCleanup(() => clearTimeout(timer));
  });

  return held.asReadonly();
}
