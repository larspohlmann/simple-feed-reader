import {
  CollectionCandidate,
  CollectionKind,
  CollectionState,
  ImportCandidateState,
} from './social-import.service';

export type ImportTab = 'accounts' | 'lists' | 'customFeeds';
export type StateFilter = 'all' | ImportCandidateState;

export interface StateOption {
  readonly state: StateFilter;
  readonly count: number;
}

export const STATE_KEYS: Readonly<Record<ImportCandidateState, string>> = {
  new: 'settings.social.importStateNew',
  subscribed: 'settings.social.importStateSubscribed',
  no_longer_followed: 'settings.social.importStateNoLongerFollowed',
};

export const FILTER_KEYS: Readonly<Record<StateFilter, string>> = {
  all: 'settings.social.importStateAll',
  ...STATE_KEYS,
};

export const CHECKBOX_KEYS: Readonly<Record<ImportCandidateState, string>> = {
  new: 'settings.social.importSubscribe',
  subscribed: 'settings.social.importAlreadySubscribed',
  no_longer_followed: 'settings.social.importUnsubscribe',
};

export const COLLECTION_CHECKBOX_KEYS: Readonly<Record<CollectionState, string>> = {
  new: 'settings.social.importAddCollection',
  subscribed: 'settings.social.importCollectionAdded',
};

export const COLLECTION_ICONS: Readonly<Record<CollectionKind, string>> = {
  home: 'home',
  list: 'list',
  custom_feed: 'dynamic_feed',
};

export const TAB_TEST_IDS: Readonly<Record<ImportTab, string>> = {
  accounts: 'social-import-follows',
  lists: 'social-import-lists',
  customFeeds: 'social-import-feeds',
};

/** Home has no id, and a list and a custom feed could share one, so the kind is part of the key. */
export function collectionKey(collection: CollectionCandidate): string {
  return `${collection.kind}:${collection.id ?? ''}`;
}

export function initialsOf(text: string): string {
  return text
    .replace(/^@/, '')
    .split(/\s+/)
    .filter((word) => word !== '')
    .slice(0, 2)
    .map((word) => Array.from(word)[0])
    .join('')
    .toLocaleUpperCase();
}
