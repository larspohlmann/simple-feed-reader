import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Problem, parseProblem } from '../../core/problem';
import { AuthService } from '../../core/auth/auth.service';
import { LanguageService } from '../../core/i18n/language.service';
import { formatDateOr, trialExpired } from '../../reader/format';
import { ButtonComponent } from '../../shared/button/button.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { ConfirmData } from '../../shared/confirm-dialog/confirm-dialog.component';
import { ConfirmService } from '../../shared/confirm-dialog/confirm.service';
import { AdminApi } from '../admin-api';
import { AdminAction, AdminUserDto, AdminUserStatus } from '../admin.models';

@Component({
  selector: 'app-admin-users',
  imports: [
    ButtonComponent,
    ErrorBannerComponent,
    SettingsGroupComponent,
    SettingsStackComponent,
    SkeletonComponent,
    TranslocoPipe,
    IconComponent,
    RouterLink,
  ],
  templateUrl: './admin-users.component.html',
  styleUrl: './admin-users.component.scss',
})
export class AdminUsersComponent implements OnInit {
  private readonly api = inject(AdminApi);
  private readonly auth = inject(AuthService);
  private readonly confirm = inject(ConfirmService);
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);

  // The label for each entry comes from the `admin.status.<key>` translation key
  // ('all' for the no-filter option).
  readonly filters: { status: AdminUserStatus | null }[] = [
    { status: null },
    { status: 'pending_approval' },
    { status: 'pending_verification' },
    { status: 'active' },
    { status: 'rejected' },
    { status: 'suspended' },
  ];

  readonly users = signal<AdminUserDto[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);
  // A failed row action (e.g. a race with another admin) is shown inline without
  // wiping the loaded list — unlike a list-load error, which legitimately has no
  // rows to show.
  readonly actionError = signal<Problem | null>(null);
  readonly filter = signal<AdminUserStatus | null>(null);

  private readonly selfId = computed(() => this.auth.user()?.id ?? -1);

  ngOnInit(): void {
    this.load();
  }

  setFilter(status: AdminUserStatus | null): void {
    this.filter.set(status);
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.actionError.set(null);
    this.api.listUsers(this.filter()).subscribe({
      next: (response) => {
        this.users.set(response.users);
        this.loading.set(false);
      },
      error: (error: HttpErrorResponse) => {
        this.error.set(parseProblem(error));
        this.loading.set(false);
      },
    });
  }

  act(user: AdminUserDto, action: AdminAction): void {
    this.actionError.set(null);
    this.api.act(user.id, action).subscribe({
      next: () => this.load(),
      error: (error: HttpErrorResponse) => this.actionError.set(parseProblem(error)),
    });
  }

  private isSelf(user: AdminUserDto): boolean {
    return user.id === this.selfId();
  }

  /** The active UI language drives the date format (via Intl), not `LOCALE_ID` —
   *  Transloco switches language at runtime, and a static `LOCALE_ID` can't follow
   *  that. Falls back to the "never" translation when the account has no login. */
  lastLoginLabel(user: AdminUserDto): string {
    return formatDateOr(
      user.lastLoginAt,
      this.language.lang(),
      this.i18n.translate('admin.neverLoggedIn'),
    );
  }

  /** The link's visible text stays the email address; the accessible name adds
   *  what activating it does, since the persistent chevron affordance is
   *  decorative-only (icons render `aria-hidden`). */
  emailLinkLabel(user: AdminUserDto): string {
    return `${user.email} — ${this.i18n.translate('admin.openDetail')}`;
  }

  /** True when the account's trial end date is in the past — the account is
   *  (or will be, on its next request) suspended by the trial. */
  trialExpired(user: AdminUserDto): boolean {
    return trialExpired(user.trialEndsAt);
  }

  canApprove(user: AdminUserDto): boolean {
    return user.status !== 'active';
  }
  canReject(user: AdminUserDto): boolean {
    return (
      !this.isSelf(user) &&
      (user.status === 'pending_approval' || user.status === 'pending_verification')
    );
  }
  canSuspend(user: AdminUserDto): boolean {
    return !this.isSelf(user) && user.status === 'active';
  }

  /** Rejecting or suspending cuts off a person's access — that is a
   *  destructive action and gets the two-step treatment: an initiating
   *  danger-outline button, then the filled-danger confirm. */
  confirmThenAct(user: AdminUserDto, action: 'reject' | 'suspend'): void {
    const data: ConfirmData = {
      title: this.i18n.translate(`admin.confirm.${action}Title`),
      message: this.i18n.translate(`admin.confirm.${action}Message`, { email: user.email }),
      confirmLabel: this.i18n.translate(`admin.${action}`),
      danger: true,
    };
    this.confirm.confirmThen(data, () => this.act(user, action));
  }
}
