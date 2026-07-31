// src/app/admin/admin-user-detail.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Problem, parseProblem } from '../core/problem';
import { AuthService } from '../core/auth.service';
import { LanguageService } from '../core/language.service';
import { formatLongDate } from '../reader/format';
import { ConfirmData, ConfirmDialogComponent } from '../reader/manage/confirm-dialog.component';
import { ButtonComponent } from '../shared/button/button.component';
import { IconComponent } from '../shared/icon/icon.component';
import { SpinnerComponent } from '../shared/spinner/spinner.component';
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
    IconComponent,
    RouterLink,
    SpinnerComponent,
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
    this.route.paramMap.subscribe((params) => {
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
        this.loading.set(false);
      },
      error: (failure: HttpErrorResponse) => {
        this.error.set(parseProblem(failure));
        this.loading.set(false);
      },
    });
  }

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

  /** The active UI language drives the date format (via Intl), not
   *  `LOCALE_ID` — Transloco switches language at runtime, and a static
   *  `LOCALE_ID` (which `DatePipe` reads) can't follow that. */
  formatDate(iso: string): string {
    return formatLongDate(iso, this.language.lang());
  }
}
