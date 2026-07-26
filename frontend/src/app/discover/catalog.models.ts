// src/app/discover/catalog.models.ts
import { TagDto } from '../reader/models';

export interface CatalogFeedDto {
  id: number;
  title: string;
  description: string | null;
  siteUrl: string | null;
  /** Path on our own origin — never a publisher URL, so the picker makes no
   *  outbound requests. Serves cached bytes or a monogram placeholder. */
  faviconUrl: string;
  subscribed: boolean;
}

export interface CatalogCategoryDto {
  id: number;
  key: string;
  name: string;
  icon: string;
  color: string;
  feeds: CatalogFeedDto[];
}

export interface OnboardingSubscribeResult {
  subscribed: number;
  skipped: number;
  skippedOverLimit: number;
  tagsCreated: TagDto[];
}
