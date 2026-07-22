// src/app/admin/admin-api.ts
import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { AdminAction, AdminUserDto, AdminUserStatus } from './admin.models';

@Injectable({ providedIn: 'root' })
export class AdminApi {
  private readonly http = inject(HttpClient);
  private readonly base = inject(API_BASE_URL);

  listUsers(status?: AdminUserStatus | null): Observable<{ users: AdminUserDto[] }> {
    let params = new HttpParams();
    if (status) params = params.set('status', status);
    return this.http.get<{ users: AdminUserDto[] }>(`${this.base}/api/admin/users`, { params });
  }

  act(id: number, action: AdminAction): Observable<{ status: AdminUserStatus }> {
    return this.http.post<{ status: AdminUserStatus }>(
      `${this.base}/api/admin/users/${id}/${action}`,
      {},
    );
  }
}
