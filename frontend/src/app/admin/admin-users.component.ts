// src/app/admin/admin-users.component.ts
import { HttpErrorResponse } from '@angular/common/http';
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { Problem, parseProblem } from '../core/problem';
import { AuthService } from '../core/auth.service';
import { IconComponent } from '../shared/icon/icon.component';
import { SpinnerComponent } from '../shared/spinner/spinner.component';
import { AdminApi } from './admin-api';
import { AdminAction, AdminUserDto, AdminUserStatus } from './admin.models';

interface Filter {
  label: string;
  status: AdminUserStatus | null;
}

@Component({
  selector: 'app-admin-users',
  imports: [RouterLink, IconComponent, SpinnerComponent],
  templateUrl: './admin-users.component.html',
  styleUrl: './admin-users.component.scss',
})
export class AdminUsersComponent implements OnInit {
  private readonly api = inject(AdminApi);
  private readonly auth = inject(AuthService);

  readonly filters: Filter[] = [
    { label: 'All', status: null },
    { label: 'Pending approval', status: 'pending_approval' },
    { label: 'Unverified', status: 'pending_verification' },
    { label: 'Active', status: 'active' },
    { label: 'Rejected', status: 'rejected' },
    { label: 'Suspended', status: 'suspended' },
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

  label(status: AdminUserStatus): string {
    return this.filters.find((f) => f.status === status)?.label ?? status;
  }
}
