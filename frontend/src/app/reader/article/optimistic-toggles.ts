import { HttpErrorResponse } from '@angular/common/http';
import { Observable, finalize } from 'rxjs';
import { parseProblem } from '../../core/problem';
import { ToastService } from '../../shared/toast/toast.service';
import { SocialCountsDto } from '../entry-social';

/** A flag shown changed at once and shown back if what `send` sends fails. */
export interface OptimisticToggle {
  key: string;
  to: boolean;
  send: () => Observable<unknown>;
  show: (value: boolean) => void;
}

/**
 * Flags toggled ahead of their answer; a toggle whose key is still on the wire for its entry is dropped, and a failed
 * one is toasted.
 */
export class OptimisticToggles {
  private readonly inFlight = new Map<number, Set<string>>();

  constructor(private readonly toast: ToastService) {}

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
          this.toast.showProblem(parseProblem(error));
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

/** The counts with `kind` moved by one toward `up`; the same counts where the network does not count it. */
export function steppedCounts(
  counts: SocialCountsDto,
  kind: keyof SocialCountsDto,
  up: boolean,
): SocialCountsDto {
  const count = counts[kind];
  return count === null ? counts : { ...counts, [kind]: steppedCount(count, up) };
}

/** A count moved by one toward `up`, never below zero. */
function steppedCount(count: number, up: boolean): number {
  return Math.max(0, count + (up ? 1 : -1));
}
