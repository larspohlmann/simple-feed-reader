import { Observable, finalize } from 'rxjs';
import { signal } from '@angular/core';

/** An action a control waits on: `pending` holds the tag it was started with until the action settles. */
export class PendingAction<T> {
  readonly pending = signal<T | null>(null);

  /** Ignored while an earlier run is pending; a failure is the action's to report. */
  run(tag: T, action: () => Observable<unknown>): void {
    if (this.pending() !== null) return;
    this.pending.set(tag);
    action()
      .pipe(finalize(() => this.pending.set(null)))
      .subscribe({ error: () => undefined });
  }
}
