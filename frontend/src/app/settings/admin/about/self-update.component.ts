import { HttpErrorResponse } from '@angular/common/http';
import {
  ChangeDetectionStrategy,
  Component,
  DestroyRef,
  NgZone,
  OnInit,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { filter, switchMap, timer } from 'rxjs';
import { AdminApi } from '../../../admin/admin-api';
import { SelfUpdatePhase, SelfUpdateStatusDto } from '../../../admin/admin.models';
import { parseProblem, problemMessage } from '../../../core/problem';
import { VersionService } from '../../../core/version.service';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';

export const SELF_UPDATE_POLL_INTERVAL_MS = 10_000;

const IN_PROGRESS: readonly SelfUpdatePhase[] = ['requested', 'running'];

@Component({
  selector: 'app-self-update',
  imports: [ButtonComponent, ErrorBannerComponent, TranslocoPipe],
  templateUrl: './self-update.component.html',
  styleUrl: './self-update.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SelfUpdateComponent implements OnInit {
  private readonly api = inject(AdminApi);
  private readonly versions = inject(VersionService);
  private readonly confirm = inject(ConfirmService);
  private readonly i18n = inject(TranslocoService);
  private readonly zone = inject(NgZone);
  private readonly destroyRef = inject(DestroyRef);

  readonly status = signal<SelfUpdateStatusDto | null>(null);
  readonly refusal = signal<string | null>(null);
  readonly latest = this.versions.latest;

  readonly inProgress = computed(() => isInProgress(this.status()));

  readonly canStart = computed(
    () =>
      this.status()?.updaterPresent === true &&
      !this.inProgress() &&
      this.versions.updateAvailable() &&
      this.latest() !== null,
  );

  readonly visible = computed(() => {
    const current = this.status();
    if (current === null) return false;
    return (
      !current.updaterPresent ||
      current.phase !== 'idle' ||
      this.canStart() ||
      this.refusal() !== null
    );
  });

  ngOnInit(): void {
    this.fetchStatus();
  }

  startUpdate(): void {
    this.confirm
      .ask({
        title: this.i18n.translate('settings.about.update.confirmTitle'),
        message: this.i18n.translate('settings.about.update.confirmMessage'),
        confirmLabel: this.i18n.translate('settings.about.update.confirm'),
      })
      .pipe(
        filter(Boolean),
        switchMap(() => {
          this.refusal.set(null);
          return this.api.requestUpdate();
        }),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (status) => this.apply(status),
        error: (error: HttpErrorResponse) => {
          this.refusal.set(problemMessage(parseProblem(error)));
          this.fetchStatus();
        },
      });
  }

  private fetchStatus(): void {
    this.api
      .updateStatus()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (status) => this.apply(status),
        error: () => this.schedulePoll(),
      });
  }

  private apply(status: SelfUpdateStatusDto): void {
    const previous = this.status();
    this.status.set(status);
    if (previous !== null && previous.phase !== 'succeeded' && status.phase === 'succeeded') {
      this.versions.load();
    }
    this.schedulePoll();
  }

  /** One timer per poll, outside the zone: a pending timer inside it would keep `whenStable()` from settling. */
  private schedulePoll(): void {
    if (!this.inProgress()) return;
    this.zone.runOutsideAngular(() =>
      timer(SELF_UPDATE_POLL_INTERVAL_MS)
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe(() => this.zone.run(() => this.fetchStatus())),
    );
  }
}

function isInProgress(status: SelfUpdateStatusDto | null): boolean {
  return status !== null && IN_PROGRESS.includes(status.phase);
}
