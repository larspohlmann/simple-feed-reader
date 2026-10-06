import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  NgZone,
  effect,
  inject,
  input,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { DebugLogEntryComponent } from '../debug-log/debug-log-entry.component';
import { SettingsApi } from '../settings-api';
import { DebugLogDetail, DebugLogEntry } from '../settings.models';

const POLL_MS = 2000;

/** The newest profile run's provider calls, polled while the run is active; a row opens to its bodies. */
@Component({
  selector: 'app-profile-debug-log',
  imports: [DebugLogEntryComponent],
  templateUrl: './profile-debug-log.component.html',
  styleUrl: './profile-debug-log.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProfileDebugLogComponent {
  private readonly api = inject(SettingsApi);
  private readonly zone = inject(NgZone);
  private readonly destroyRef = inject(DestroyRef);

  readonly running = input(false);
  readonly runId = input<number | null>(null);

  readonly entries = signal<DebugLogEntry[]>([]);
  readonly expanded = signal<ReadonlySet<number>>(new Set());
  readonly details = signal<ReadonlyMap<number, DebugLogDetail>>(new Map());

  private timer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    effect(() => {
      this.runId();
      const running = this.running();
      untracked(() => this.fetch(running));
    });
    this.destroyRef.onDestroy(() => this.stop());
  }

  toggle(id: number): void {
    const next = new Set(this.expanded());
    if (next.has(id)) {
      next.delete(id);
    } else {
      next.add(id);
      this.ensureDetail(id);
    }
    this.expanded.set(next);
  }

  private ensureDetail(id: number): void {
    if (this.details().has(id)) return;
    this.api
      .debugLogEntry(id)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((detail) => this.details.set(new Map(this.details()).set(id, detail)));
  }

  private fetch(running: boolean): void {
    this.stop();
    this.api
      .profileDebugLog()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (payload) => this.entries.set(payload.entries),
        // A failed read keeps the last entries; the next poll tries again.
        error: () => undefined,
      });
    if (!running) return;
    this.zone.runOutsideAngular(() => {
      this.timer = setTimeout(() => this.zone.run(() => this.fetch(this.running())), POLL_MS);
    });
  }

  private stop(): void {
    if (this.timer === null) return;
    clearTimeout(this.timer);
    this.timer = null;
  }
}
