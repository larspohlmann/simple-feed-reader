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
import { filter, finalize, switchMap, take, timer } from 'rxjs';
import { AdminApi } from '../../../admin/admin-api';
import { SelfUpdateStatusDto } from '../../../admin/admin.models';
import { parseProblem, problemMessage } from '../../../core/problem';
import { isTabHidden, onTabVisibilityChange } from '../../../core/tab-visibility';
import { LanguageService } from '../../../core/i18n/language.service';
import { VersionService } from '../../../core/version.service';
import { formatLongDateTime } from '../../../reader/format';
import { ButtonComponent } from '../../../shared/button/button.component';
import { ConfirmService } from '../../../shared/confirm-dialog/confirm.service';
import { DisclosureComponent } from '../../../shared/disclosure/disclosure.component';
import { ErrorBannerComponent } from '../../../shared/error-banner/error-banner.component';
import { SpinnerComponent } from '../../../shared/spinner/spinner.component';

export const SELF_UPDATE_POLL_INTERVAL_MS = 10_000;

@Component({
  selector: 'app-self-update',
  imports: [
    ButtonComponent,
    DisclosureComponent,
    ErrorBannerComponent,
    SpinnerComponent,
    TranslocoPipe,
  ],
  templateUrl: './self-update.component.html',
  styleUrl: './self-update.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SelfUpdateComponent implements OnInit {
  private readonly api = inject(AdminApi);
  private readonly versions = inject(VersionService);
  private readonly confirm = inject(ConfirmService);
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);
  private readonly zone = inject(NgZone);
  private readonly destroyRef = inject(DestroyRef);

  readonly status = signal<SelfUpdateStatusDto | null>(null);
  readonly refusal = signal<string | null>(null);
  readonly starting = signal(false);
  readonly checking = signal(false);
  readonly checkOutcome = signal<'none' | 'succeeded' | 'failed'>('none');
  readonly reconnecting = signal(false);
  private pollDue = false;

  readonly inProgress = computed(() => {
    const phase = this.status()?.phase;
    return phase === 'requested' || phase === 'running';
  });

  readonly finished = computed(() => {
    const phase = this.status()?.phase;
    return phase === 'succeeded' || phase === 'failed';
  });

  readonly finishedAt = computed(() => {
    const finishedAt = this.status()?.finishedAt;
    return finishedAt ? formatLongDateTime(finishedAt, this.language.lang()) : '';
  });

  readonly offeredRelease = computed(() => {
    if (this.status()?.updaterPresent !== true) return null;
    return this.versions.updateAvailable() ? this.versions.latest() : null;
  });

  readonly showsManualHint = computed(() => {
    const current = this.status();
    return current !== null && !current.updaterPresent && current.phase === 'idle';
  });

  readonly upToDate = computed(
    () => this.checkOutcome() === 'succeeded' && !this.versions.updateAvailable(),
  );

  constructor() {
    onTabVisibilityChange((hidden) => {
      if (hidden || !this.pollDue) return;
      this.pollDue = false;
      this.zone.run(() => this.fetchStatus());
    });
  }

  ngOnInit(): void {
    this.fetchStatus();
  }

  startUpdate(): void {
    this.starting.set(true);
    this.confirm
      .ask({
        title: this.i18n.translate('settings.about.update.confirmTitle'),
        message: this.i18n.translate('settings.about.update.confirmMessage'),
        confirmLabel: this.i18n.translate('settings.about.update.confirm'),
      })
      .pipe(
        take(1),
        filter(Boolean),
        switchMap(() => {
          this.refusal.set(null);
          return this.api.requestUpdate();
        }),
        finalize(() => this.starting.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: (status) => this.apply(status),
        error: (error: HttpErrorResponse) =>
          this.rereadAfterRefusal(problemMessage(parseProblem(error))),
      });
  }

  checkNow(): void {
    this.checking.set(true);
    this.checkOutcome.set('none');
    this.versions
      .checkNow()
      .pipe(
        finalize(() => this.checking.set(false)),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe({
        next: () => this.checkOutcome.set('succeeded'),
        error: () => this.checkOutcome.set('failed'),
      });
  }

  private rereadAfterRefusal(message: string): void {
    this.api
      .updateStatus()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (status) => {
          this.apply(status);
          this.refusal.set(message);
        },
        error: () => this.refusal.set(message),
      });
  }

  private fetchStatus(): void {
    this.api
      .updateStatus()
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        next: (status) => this.apply(status),
        error: () => {
          this.reconnecting.set(this.inProgress());
          this.schedulePoll();
        },
      });
  }

  private apply(status: SelfUpdateStatusDto): void {
    const previousPhase = this.status()?.phase;
    this.status.set(status);
    this.reconnecting.set(false);
    if (previousPhase !== undefined && previousPhase !== status.phase) {
      this.refusal.set(null);
      if (status.phase === 'succeeded') this.versions.load();
    }
    this.schedulePoll();
  }

  // Outside the zone: a pending timer inside it keeps `whenStable()` from settling.
  private schedulePoll(): void {
    if (this.status() !== null && !this.inProgress()) return;
    this.zone.runOutsideAngular(() =>
      timer(SELF_UPDATE_POLL_INTERVAL_MS)
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe(() => this.pollUnlessHidden()),
    );
  }

  private pollUnlessHidden(): void {
    if (isTabHidden()) {
      this.pollDue = true;
      return;
    }
    this.zone.run(() => this.fetchStatus());
  }
}
