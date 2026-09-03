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
  feedsCount: number;
  tagsCount: number;
  /** null means the account has never signed in. */
  lastLoginAt: string | null;
  /** ISO 8601, or null when the account has no trial. */
  trialEndsAt: string | null;
  /** Per-user subscription cap, or null to use the global default. */
  maxSubscriptions: number | null;
}

/** Narrower sibling of {@link AdminUserDto}, not an extension: carries
 *  `locale` but not `feedsCount`/`tagsCount` (see {@link AdminUserFootprintDto}).
 *  Mirrors backend `AdminUserAccount` field-for-field. */
export interface AdminUserAccountDto {
  id: number;
  email: string;
  status: AdminUserStatus;
  roles: string[];
  locale: string;
  createdAt: string;
  approvedAt: string | null;
  /** null means the account has never signed in. */
  lastLoginAt: string | null;
  identities: string[];
}

export interface AdminUserFootprintDto {
  feedsCount: number;
  tagsCount: number;
  /** The effective per-user cap: the account's own `maxSubscriptions` when
   *  set, otherwise the global default. */
  feedsLimit: number;
  staleFeedsCount: number;
  /** Newest fetch across the account's feeds; null when it has no feeds. */
  lastRefreshAt: string | null;
  dormant: boolean;
}

export interface AdminUserTagDto {
  id: number;
  name: string;
  color: string | null;
  icon: string | null;
  position: number;
  /** How many of this account's feeds carry the tag. */
  feedsCount: number;
}

export interface AdminUserSubscriptionDto {
  id: number;
  /** The feed's own title; null until the first successful fetch. */
  title: string | null;
  customTitle: string | null;
  url: string;
  position: number;
  createdAt: string;
  /** null when the underlying feed has never been fetched. */
  lastFetchedAt: string | null;
  /** Carries `icon` alongside `color` so a subscription's tag chips render the
   *  same glyph the account's own tag list shows for it, rather than falling
   *  back to a plain colour dot. */
  tags: { id: number; name: string; color: string | null; icon: string | null }[];
}

/** Kept off {@link AdminUserAccountDto} because the backend detail response
 *  carries it as a sibling `limits` object, not a field on `user`. Mirrors
 *  backend `AdminUserLimits` field-for-field. */
export interface AdminUserLimitsDto {
  /** ISO 8601, or null when the account has no trial. */
  trialEndsAt: string | null;
  /** Per-user subscription cap, or null to use the global default. */
  maxSubscriptions: number | null;
}

export interface AdminUserDetailDto {
  user: AdminUserAccountDto;
  footprint: AdminUserFootprintDto;
  tags: AdminUserTagDto[];
  subscriptions: AdminUserSubscriptionDto[];
  limits: AdminUserLimitsDto;
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
