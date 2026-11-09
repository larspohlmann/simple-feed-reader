import { TagDto } from '../../models';

export interface CatalogFeedDto {
  id: number;
  title: string;
  description: string | null;
  siteUrl: string | null;
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
