import { Signal, computed, effect, signal } from '@angular/core';

/** True once `source` has stayed true for `delayMs`; false the moment it drops.
 *  Runs in an injection context (a field initializer, a constructor), as the
 *  effect behind it does. */
export function heldFor(source: () => boolean, delayMs: number): Signal<boolean> {
  const held = signal(false);
  // Memoised so the clock restarts only when the verdict flips, not whenever
  // one of the signals the source reads is rewritten to the same verdict.
  const wanted = computed(source);
  effect((onCleanup) => {
    if (!wanted()) {
      held.set(false);
      return;
    }
    const timer = setTimeout(() => held.set(true), delayMs);
    onCleanup(() => clearTimeout(timer));
  });

  return held.asReadonly();
}
