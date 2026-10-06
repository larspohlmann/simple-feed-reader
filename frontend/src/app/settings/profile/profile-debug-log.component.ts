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
import { DebugLogDetails } from '../debug-log/debug-log-details.service';
import { DebugLogEntryComponent } from '../debug-log/debug-log-entry.component';
import { SettingsApi } from '../settings-api';
import { DebugLogEntry } from '../settings.models';

const POLL_MS = 2000;

/** The newest profile run's provider calls, polled while the run is active; a row opens to its bodies. */
@Component({
  selector: 'app-profile-debug-log',
  imports: [DebugLogEntryComponent],
  providers: [DebugLogDetails],
  templateUrl: './profile-debug-log.component.html',
  styleUrl: './profile-debug-log.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ProfileDebugLogComponent {
  private readonly api = inject(SettingsApi);
  private readonly zone = inject(NgZone);
  private readonly destroyRef = inject(DestroyRef);
  readonly details = inject(DebugLogDetails);

  readonly running = input(false);
  readonly runId = input<number | null>(null);

  readonly entries = signal<DebugLogEntry[]>([]);

  private timer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    effect(() => {
      this.runId();
      const running = this.running();
      untracked(() => this.fetch(running));
    });
    this.destroyRef.onDestroy(() => this.stop());
  }

  private fetch(running: boolean): void {
    this.stop();
    this.api
      .profileDebugLog()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (payload) => {
          this.details.observe(payload.entries);
          this.entries.set(payload.entries);
        },
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
