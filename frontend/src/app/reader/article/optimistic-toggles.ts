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

/** A change shown at once and reverted if what `send` sends fails. */
export interface OptimisticChange {
  key: string;
  apply: () => void;
  revert: () => void;
  send: () => Observable<unknown>;
}

/**
 * Flags toggled ahead of their answer; a toggle whose key is still on the wire for its item is dropped, and a failed
 * one is toasted.
 */
export class OptimisticToggles {
  private readonly inFlight = new Map<string, Set<string>>();

  constructor(private readonly toast: ToastService) {}

  isBusy(itemKey: string): boolean {
    return this.inFlight.has(itemKey);
  }

  run(itemKey: string, toggle: OptimisticToggle): void {
    this.change(itemKey, {
      key: toggle.key,
      apply: () => toggle.show(toggle.to),
      revert: () => toggle.show(!toggle.to),
      send: toggle.send,
    });
  }

  change(itemKey: string, change: OptimisticChange): void {
    const keys = this.inFlight.get(itemKey) ?? new Set<string>();
    if (keys.has(change.key)) return;
    keys.add(change.key);
    this.inFlight.set(itemKey, keys);
    change.apply();
    change
      .send()
      .pipe(finalize(() => this.settle(itemKey, change.key)))
      .subscribe({
        error: (error: HttpErrorResponse) => {
          change.revert();
          this.toast.showProblem(parseProblem(error));
        },
      });
  }

  clear(): void {
    this.inFlight.clear();
  }

  private settle(itemKey: string, key: string): void {
    const keys = this.inFlight.get(itemKey);
    keys?.delete(key);
    if (keys?.size === 0) this.inFlight.delete(itemKey);
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
