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
  template: `
    <header class="bar">
      <a class="back" routerLink="/"><app-icon name="arrow_back" [size]="18" /> Reader</a>
      <h1>Users</h1>
    </header>

    <div class="filters" role="group" aria-label="Filter by status">
      @for (f of filters; track f.label) {
        <button [class.active]="filter() === f.status" (click)="setFilter(f.status)">
          {{ f.label }}
        </button>
      }
    </div>

    @if (loading()) {
      <div class="pad"><app-spinner /></div>
    } @else if (error()) {
      <div class="banner" role="alert">
        {{ error()!.detail || error()!.title }}
        <button (click)="load()">Retry</button>
      </div>
    } @else if (users().length === 0) {
      <p class="pad muted">No users match this filter.</p>
    } @else {
      <ul class="users">
        @for (u of users(); track u.id) {
          <li>
            <div class="who">
              <span class="email">{{ u.email }}</span>
              <span class="meta">
                <span class="badge" [attr.data-s]="u.status">{{ label(u.status) }}</span>
                @if (u.identities.length) {
                  <span class="prov">{{ u.identities.join(', ') }}</span>
                }
              </span>
            </div>
            <div class="acts">
              @if (canApprove(u)) {
                <button class="ok" (click)="act(u, 'approve')">Approve</button>
              }
              @if (canReject(u)) {
                <button class="warn" (click)="act(u, 'reject')">Reject</button>
              }
              @if (canSuspend(u)) {
                <button class="warn" (click)="act(u, 'suspend')">Suspend</button>
              }
            </div>
          </li>
        }
      </ul>
    }
  `,
  styles: [
    `
      :host {
        display: block;
        max-width: 820px;
        margin: 0 auto;
        padding: var(--space-4);
      }
      .bar {
        display: flex;
        align-items: center;
        gap: var(--space-4);
      }
      .back {
        display: inline-flex;
        align-items: center;
        gap: var(--space-1);
        color: var(--text-secondary);
        text-decoration: none;
      }
      h1 {
        font-size: var(--fs-xl);
        margin: 0;
      }
      .filters {
        display: flex;
        flex-wrap: wrap;
        gap: var(--space-1);
        margin: var(--space-4) 0;
      }
      .filters button {
        padding: var(--space-1) var(--space-3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
        color: var(--text-secondary);
        cursor: pointer;
      }
      .filters button.active {
        background: var(--accent-soft);
        color: var(--accent);
        border-color: var(--accent);
      }
      .users {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: var(--space-1);
      }
      .users li {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--space-3);
        padding: var(--space-3);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-1);
      }
      .who {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
      }
      .email {
        color: var(--text-primary);
      }
      .meta {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .badge {
        padding: 0 var(--space-2);
        border-radius: var(--radius);
        background: var(--surface-0);
        color: var(--text-secondary);
      }
      .badge[data-s='active'] {
        background: var(--bg-success);
        color: var(--success);
      }
      .badge[data-s='suspended'],
      .badge[data-s='rejected'] {
        background: var(--bg-danger);
        color: var(--danger);
      }
      .acts {
        display: flex;
        gap: var(--space-2);
        flex: 0 0 auto;
      }
      .acts button {
        padding: var(--space-1) var(--space-3);
        border-radius: var(--radius);
        border: 1px solid var(--border-strong);
        background: var(--surface-1);
        color: var(--text-primary);
        cursor: pointer;
      }
      .acts button.ok {
        background: var(--accent);
        color: var(--on-accent);
        border-color: var(--accent);
      }
      .acts button.warn {
        color: var(--danger);
        border-color: var(--danger);
      }
      .banner {
        padding: var(--space-3);
        border-radius: var(--radius);
        background: var(--bg-danger);
        color: var(--danger);
        display: flex;
        justify-content: space-between;
        gap: var(--space-3);
      }
      .banner button {
        background: none;
        border: none;
        color: var(--danger);
        cursor: pointer;
        text-decoration: underline;
      }
      .pad {
        padding: var(--space-5);
        text-align: center;
      }
      .muted {
        color: var(--text-muted);
      }
    `,
  ],
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
    this.api.act(u.id, action).subscribe({
      next: () => this.load(),
      error: (e: HttpErrorResponse) => this.error.set(parseProblem(e)),
    });
  }

  private isSelf(u: AdminUserDto): boolean {
    return u.id === this.selfId();
  }

  canApprove(u: AdminUserDto): boolean {
    return u.status !== 'active';
  }
  canReject(u: AdminUserDto): boolean {
    return !this.isSelf(u) && (u.status === 'pending_approval' || u.status === 'pending_verification');
  }
  canSuspend(u: AdminUserDto): boolean {
    return !this.isSelf(u) && u.status === 'active';
  }

  label(status: AdminUserStatus): string {
    return this.filters.find((f) => f.status === status)?.label ?? status;
  }
}
