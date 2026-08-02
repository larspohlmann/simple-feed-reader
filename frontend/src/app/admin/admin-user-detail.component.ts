// src/app/admin/admin-user-detail.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Problem, parseProblem } from '../core/problem';
import { AuthService } from '../core/auth.service';
import { LanguageService } from '../core/language.service';
import { formatDateOr, formatLongDate, relativeTime, trialDaysRemaining } from '../reader/format';
import {
  ConfirmData,
  ConfirmDialogComponent,
} from '../shared/confirm-dialog/confirm-dialog.component';
import { ButtonComponent } from '../shared/button/button.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { FieldComponent } from '../shared/field/field.component';
import { IconComponent } from '../shared/icon/icon.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';
import { SkeletonComponent } from '../shared/skeleton/skeleton.component';
import { TagGlyphComponent } from '../shared/tag-glyph/tag-glyph.component';
import { AdminApi } from './admin-api';
import { AdminAction, AdminUserDetailDto } from './admin.models';

/** Everything the admin knows about one account: who they are, how active they
 *  are, and exactly which tags and feeds they own. Read-only apart from the
 *  account actions the list page also offers. */
@Component({
  selector: 'app-admin-user-detail',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    FieldComponent,
    IconComponent,
    RouterLink,
    SettingsCardComponent,
    SkeletonComponent,
    TagGlyphComponent,
    TranslocoPipe,
  ],
  templateUrl: './admin-user-detail.component.html',
  styleUrl: './admin-user-detail.component.scss',
})
export class AdminUserDetailComponent {
  private readonly api = inject(AdminApi);
  private readonly auth = inject(AuthService);
  private readonly dialog = inject(Dialog);
  private readonly i18n = inject(TranslocoService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);

  readonly language = inject(LanguageService);

  readonly detail = signal<AdminUserDetailDto | null>(null);
  readonly loading = signal(true);
  readonly error = signal<Problem | null>(null);
  // A failed action (e.g. a race with another admin) is shown inline without
  // wiping the loaded detail — unlike a load error, which legitimately has
  // nothing to show.
  readonly actionError = signal<Problem | null>(null);

  private id = 0;

  constructor() {
    this.route.paramMap.pipe(takeUntilDestroyed()).subscribe((params) => {
      this.id = Number(params.get('id'));
      this.load();
    });
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.actionError.set(null);
    this.api.userDetail(this.id).subscribe({
      next: (detail) => {
        this.detail.set(detail);
        this.maxFeeds.set(detail.limits.maxSubscriptions);
        this.loading.set(false);
      },
      error: (failure: HttpErrorResponse) => {
        this.error.set(parseProblem(failure));
        this.loading.set(false);
      },
    });
  }

  /** The card's heading: the account's own email once it has loaded, so the
   *  page title names the account you are looking at, exactly like the list
   *  page's row already does. Before that — while loading, or after a load
   *  error — there is no email to show yet, so the card falls back to a
   *  generic title rather than rendering an empty heading. */
  readonly cardHeading = computed(
    () => this.detail()?.user.email ?? this.i18n.translate('admin.detail.title'),
  );

  /** null while the account is still loading, since none of the action
   *  affordances below have anything to key off before that. */
  private readonly isSelf = computed(() => this.detail()?.user.id === this.auth.user()?.id);

  canApprove(): boolean {
    const status = this.detail()?.user.status;
    return status !== undefined && status !== 'active';
  }

  canReject(): boolean {
    const status = this.detail()?.user.status;
    return !this.isSelf() && (status === 'pending_approval' || status === 'pending_verification');
  }

  canSuspend(): boolean {
    return !this.isSelf() && this.detail()?.user.status === 'active';
  }

  /** Whether the heading row has anything to project into `cardActions`.
   *  Kept as one condition, rather than three separate `@if`s each wrapping
   *  their own button, so the projected content stays a single block one
   *  level below `<app-settings-card>` — see docs/design-language.md's
   *  `<app-settings-card>` entry for why that depth matters. */
  readonly hasActions = computed(() => this.canApprove() || this.canReject() || this.canSuspend());

  act(action: AdminAction): void {
    this.actionError.set(null);
    this.api.act(this.id, action).subscribe({
      next: () => this.load(),
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  /** Rejecting or suspending cuts off a person's access — that is a
   *  destructive action and gets the two-step treatment: an initiating
   *  danger-outline button, then the filled-danger confirm. Mirrors
   *  AdminUsersComponent.confirmThenAct. */
  confirmThenAct(action: 'reject' | 'suspend'): void {
    const email = this.detail()?.user.email ?? '';
    const data: ConfirmData = {
      title: this.i18n.translate(`admin.confirm.${action}Title`),
      message: this.i18n.translate(`admin.confirm.${action}Message`, { email }),
      confirmLabel: this.i18n.translate(`admin.${action}`),
      danger: true,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.act(action);
    });
  }

  /** Deletion is irreversible and takes the account's content with it, so it
   *  gets the strongest treatment the app has: a danger-outline initiator, and
   *  a confirm the admin must type the target's email address to enable. */
  confirmThenDelete(): void {
    const email = this.detail()?.user.email ?? '';
    const data: ConfirmData = {
      title: this.i18n.translate('admin.confirm.deleteTitle'),
      message: this.i18n.translate('admin.confirm.deleteMessage', { email }),
      confirmLabel: this.i18n.translate('admin.delete'),
      danger: true,
      requireText: email,
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.deleteAccount();
    });
  }

  private deleteAccount(): void {
    this.actionError.set(null);
    this.api.deleteUser(this.id).subscribe({
      next: () => void this.router.navigate(['/admin/users']),
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  /** The trial's high-level state, derived from the end date and status. */
  readonly trialState = computed<'none' | 'active' | 'expired'>(() => {
    const endsAt = this.detail()?.limits.trialEndsAt ?? null;
    if (endsAt === null) return 'none';
    return trialDaysRemaining(endsAt) !== null ? 'active' : 'expired';
  });

  /** Whole days left in an active trial (0 when not active). */
  readonly trialDaysLeft = computed(
    () => trialDaysRemaining(this.detail()?.limits.trialEndsAt ?? null) ?? 0,
  );

  /** True when the account is suspended and its trial end date is in the past —
   *  i.e. the suspension came from the trial, not from a manual admin action. */
  readonly suspendedByTrial = computed(
    () => this.detail()?.user.status === 'suspended' && this.trialState() === 'expired',
  );

  readonly trialDays = signal(14);
  readonly maxFeeds = signal<number | null>(null);

  startTrial(): void {
    this.actionError.set(null);
    this.api.startTrial(this.id, this.trialDays()).subscribe({
      next: () => this.load(),
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  clearTrial(): void {
    this.actionError.set(null);
    this.api.clearTrial(this.id).subscribe({
      next: () => this.load(),
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  saveMaxFeeds(): void {
    this.actionError.set(null);
    this.api.setSubscriptionLimit(this.id, this.maxFeeds()).subscribe({
      next: () => this.load(),
      error: (failure: HttpErrorResponse) => this.actionError.set(parseProblem(failure)),
    });
  }

  /** The active UI language drives the date format (via Intl), not
   *  `LOCALE_ID` — Transloco switches language at runtime, and a static
   *  `LOCALE_ID` (which `DatePipe` reads) can't follow that. */
  formatDate(iso: string): string {
    return formatLongDate(iso, this.language.lang());
  }

  /** How long ago a date was, e.g. "6 months ago" — the age half of the
   *  identity card's "created date with age" field. */
  ageLabel(iso: string): string {
    return relativeTime(iso, this.language.lang());
  }

  /** The account's approval date, or an explicit localised "never" when it
   *  has not yet been approved. Shares {@link formatDateOr} with every other
   *  "date, or never" field on this page, so the fallback convention cannot
   *  drift between them. */
  approvedLabel(iso: string | null): string {
    return formatDateOr(
      iso,
      this.language.lang(),
      this.i18n.translate('admin.detail.neverApproved'),
    );
  }

  /** The account's last sign-in, or "never" — the Activity card's mirror of
   *  the users list's own lastLogin column. */
  loginLabel(iso: string | null): string {
    return formatDateOr(iso, this.language.lang(), this.i18n.translate('admin.neverLoggedIn'));
  }

  /** The newest fetch across the account's feeds (Activity card) or a single
   *  feed's own freshness (a Feeds-list row) — both are "never" when nothing
   *  has been fetched, so both share this one label. */
  lastFetchedLabel(iso: string | null): string {
    return formatDateOr(
      iso,
      this.language.lang(),
      this.i18n.translate('admin.detail.neverRefreshed'),
    );
  }
}
