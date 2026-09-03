import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import {
  AdminAction,
  AdminCatalogCategoryDto,
  AdminCatalogFeedDto,
  AdminUserDetailDto,
  AdminUserDto,
  AdminUserStatus,
  BundledCatalogInfo,
  CatalogImportCounts,
  CatalogWarmReport,
  ImportMode,
} from './admin.models';

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

  userDetail(id: number): Observable<AdminUserDetailDto> {
    return this.http.get<AdminUserDetailDto>(`${this.base}/api/admin/users/${id}`);
  }

  startTrial(
    id: number,
    days: number,
  ): Observable<{ status: AdminUserStatus; trialEndsAt: string | null }> {
    return this.http.post<{ status: AdminUserStatus; trialEndsAt: string | null }>(
      `${this.base}/api/admin/users/${id}/trial`,
      { days },
    );
  }

  clearTrial(id: number): Observable<{ status: AdminUserStatus; trialEndsAt: string | null }> {
    return this.http.delete<{ status: AdminUserStatus; trialEndsAt: string | null }>(
      `${this.base}/api/admin/users/${id}/trial`,
    );
  }

  deleteUser(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/admin/users/${id}`);
  }

  setSubscriptionLimit(
    id: number,
    maxSubscriptions: number | null,
  ): Observable<{ maxSubscriptions: number | null }> {
    return this.http.put<{ maxSubscriptions: number | null }>(
      `${this.base}/api/admin/users/${id}/subscription-limit`,
      { maxSubscriptions },
    );
  }

  catalog(): Observable<{
    categories: AdminCatalogCategoryDto[];
    feeds: AdminCatalogFeedDto[];
  }> {
    return this.http.get<{ categories: AdminCatalogCategoryDto[]; feeds: AdminCatalogFeedDto[] }>(
      `${this.base}/api/admin/catalog`,
    );
  }

  saveCategory(
    id: number | null,
    body: Omit<AdminCatalogCategoryDto, 'id' | 'position'>,
  ): Observable<{ category: AdminCatalogCategoryDto }> {
    const url = `${this.base}/api/admin/catalog/categories${id === null ? '' : `/${id}`}`;
    return id === null
      ? this.http.post<{ category: AdminCatalogCategoryDto }>(url, body)
      : this.http.patch<{ category: AdminCatalogCategoryDto }>(url, body);
  }

  deleteCategory(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/admin/catalog/categories/${id}`);
  }

  reorderCategories(ids: number[]): Observable<void> {
    return this.http.patch<void>(`${this.base}/api/admin/catalog/categories/reorder`, { ids });
  }

  saveFeed(
    id: number | null,
    body: Omit<AdminCatalogFeedDto, 'id' | 'position' | 'faviconFetchedAt' | 'faviconFailedAt'>,
  ): Observable<{ feed: AdminCatalogFeedDto }> {
    const url = `${this.base}/api/admin/catalog/feeds${id === null ? '' : `/${id}`}`;
    return id === null
      ? this.http.post<{ feed: AdminCatalogFeedDto }>(url, body)
      : this.http.patch<{ feed: AdminCatalogFeedDto }>(url, body);
  }

  deleteFeed(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/admin/catalog/feeds/${id}`);
  }

  reorderFeeds(ids: number[]): Observable<void> {
    return this.http.patch<void>(`${this.base}/api/admin/catalog/feeds/reorder`, { ids });
  }

  refreshFavicon(id: number): Observable<{ feed: AdminCatalogFeedDto }> {
    return this.http.post<{ feed: AdminCatalogFeedDto }>(
      `${this.base}/api/admin/catalog/feeds/${id}/favicon`,
      {},
    );
  }

  importCatalog(mode: ImportMode, document: string): Observable<CatalogImportCounts> {
    return this.http.post<CatalogImportCounts>(`${this.base}/api/admin/catalog/import`, {
      mode,
      document,
    });
  }

  bundledCatalog(): Observable<BundledCatalogInfo> {
    return this.http.get<BundledCatalogInfo>(`${this.base}/api/admin/catalog/bundled`);
  }

  importBundledCatalog(mode: ImportMode): Observable<CatalogImportCounts> {
    return this.http.post<CatalogImportCounts>(`${this.base}/api/admin/catalog/import/bundled`, {
      mode,
    });
  }

  warmFavicons(): Observable<CatalogWarmReport> {
    return this.http.post<CatalogWarmReport>(`${this.base}/api/admin/catalog/favicons/warm`, {});
  }
}
