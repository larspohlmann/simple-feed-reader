// src/app/admin/admin.models.ts
export type AdminUserStatus =
  'pending_verification' | 'pending_approval' | 'active' | 'rejected' | 'suspended';

export interface AdminUserDto {
  id: number;
  email: string;
  status: AdminUserStatus;
  roles: string[];
  createdAt: string;
  approvedAt: string | null;
  identities: string[];
}

export type AdminAction = 'approve' | 'reject' | 'suspend';

export type ImportMode = 'merge' | 'replace';

/** The colour a brand-new category starts with (also the pre-#180 default). */
export const DEFAULT_CATEGORY_COLOR = '#3b82f6';

export interface AdminCatalogCategoryDto {
  id: number;
  key: string;
  name: string;
  icon: string;
  color: string;
  position: number;
  enabled: boolean;
  /** Locked rows survive an import untouched — neither overwritten nor deleted. */
  locked: boolean;
}

export interface BundledCatalogInfo {
  available: boolean;
  categories: number;
  feeds: number;
}

export interface CatalogWarmReport {
  warmed: number;
  failed: number;
  remaining: number;
}

export interface CatalogImportCounts {
  categoriesCreated: number;
  categoriesUpdated: number;
  categoriesRemoved: number;
  feedsCreated: number;
  feedsUpdated: number;
  feedsRemoved: number;
  lockedSkipped: number;
}

export interface AdminCatalogFeedDto {
  id: number;
  categoryId: number;
  title: string;
  url: string;
  siteUrl: string | null;
  description: string | null;
  sourceFormat: string;
  position: number;
  enabled: boolean;
  /** Locked rows survive an import untouched — neither overwritten nor deleted. */
  locked: boolean;
  faviconFetchedAt: string | null;
  faviconFailedAt: string | null;
}
