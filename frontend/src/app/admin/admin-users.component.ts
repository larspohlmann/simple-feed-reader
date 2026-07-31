// src/app/admin/admin-users.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { Problem, parseProblem } from '../core/problem';
import { AuthService } from '../core/auth.service';
import { ButtonComponent } from '../shared/button/button.component';
import { SpinnerComponent } from '../shared/spinner/spinner.component';
import { ConfirmData, ConfirmDialogComponent } from '../reader/manage/confirm-dialog.component';
import { AdminApi } from './admin-api';
import { AdminAction, AdminUserDto, AdminUserStatus } from './admin.models';

@Component({
  selector: 'app-admin-users',
  imports: [ButtonComponent, SpinnerComponent, TranslocoPipe],
  templateUrl: './admin-users.component.html',
  styleUrl: './admin-users.component.scss',
})
export class AdminUsersComponent implements OnInit {
  private readonly api = inject(AdminApi);
  private readonly auth = inject(AuthService);
  private readonly dialog = inject(Dialog);
  private readonly i18n = inject(TranslocoService);

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
      next: (r) => {
        this.users.set(r.users);
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.loading.set(false);
      },
    });
  }

  act(u: AdminUserDto, action: AdminAction): void {
    this.actionError.set(null);
    this.api.act(u.id, action).subscribe({
      next: () => this.load(),
      error: (e: HttpErrorResponse) => this.actionError.set(parseProblem(e)),
    });
  }

  private isSelf(u: AdminUserDto): boolean {
    return u.id === this.selfId();
  }

  canApprove(u: AdminUserDto): boolean {
    return u.status !== 'active';
  }
  canReject(u: AdminUserDto): boolean {
    return (
      !this.isSelf(u) && (u.status === 'pending_approval' || u.status === 'pending_verification')
    );
  }
  canSuspend(u: AdminUserDto): boolean {
    return !this.isSelf(u) && u.status === 'active';
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
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      // A destructive confirmation is an alert, not a plain dialog; the role
      // belongs on the CDK's modal container.
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.act(user, action);
    });
  }
}
