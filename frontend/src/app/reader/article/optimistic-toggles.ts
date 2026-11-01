import { HttpErrorResponse } from '@angular/common/http';
import { Observable, finalize } from 'rxjs';
import { Problem, parseProblem } from '../../core/problem';

/** A flag shown changed at once and shown back if what `send` sends fails. */
export interface OptimisticToggle {
  key: string;
  to: boolean;
  send: () => Observable<unknown>;
  show: (value: boolean) => void;
  onError: (problem: Problem) => void;
}

/** Flags toggled ahead of their answer; a toggle whose key is still on the wire for its entry is dropped. */
export class OptimisticToggles {
  private readonly inFlight = new Map<number, Set<string>>();

  isBusy(entryId: number): boolean {
    return this.inFlight.has(entryId);
  }

  run(entryId: number, toggle: OptimisticToggle): void {
    const keys = this.inFlight.get(entryId) ?? new Set<string>();
    if (keys.has(toggle.key)) return;
    keys.add(toggle.key);
    this.inFlight.set(entryId, keys);
    toggle.show(toggle.to);
    toggle
      .send()
      .pipe(finalize(() => this.settle(entryId, toggle.key)))
      .subscribe({
        error: (error: HttpErrorResponse) => {
          toggle.show(!toggle.to);
          toggle.onError(parseProblem(error));
        },
      });
  }

  clear(): void {
    this.inFlight.clear();
  }

  private settle(entryId: number, key: string): void {
    const keys = this.inFlight.get(entryId);
    keys?.delete(key);
    if (keys?.size === 0) this.inFlight.delete(entryId);
  }
}

/** A count moved by one toward `up`, never below zero. */
export function steppedCount(count: number, up: boolean): number {
  return Math.max(0, count + (up ? 1 : -1));
}
