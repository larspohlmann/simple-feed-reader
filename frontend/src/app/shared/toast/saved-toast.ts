import { Signal, WritableSignal, effect, inject } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { Problem } from '../../core/problem';
import { CONFIRMATION_DURATION_MS, ToastService } from './toast.service';

interface SavedSource {
  readonly saved: WritableSignal<boolean>;
  readonly failure: Signal<Problem | null>;
}

/**
 * One success signal, fired on the actual HTTP success rather than the click:
 * every persist sets `saved`, so this toasts once and resets the flag. A
 * rejected save never sets `saved`, so it stays silent. Call from a
 * constructor: it registers an effect in the injection context.
 */
export function toastOnSaved(source: SavedSource, messageKey: string): void {
  const toast = inject(ToastService);
  const i18n = inject(TranslocoService);

  effect(() => {
    if (source.saved() && !source.failure()) {
      toast.show({ message: i18n.translate(messageKey), durationMs: CONFIRMATION_DURATION_MS });
      source.saved.set(false);
    }
  });
}
