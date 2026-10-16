import {
  CollectionCandidate,
  CollectionKind,
  CollectionState,
  FollowCandidate,
  ImportCandidateState,
  ImportCandidates,
} from './social-import.service';
import { initialsOf } from '../../shared/initials';

export type ImportTab = 'accounts' | 'lists' | 'customFeeds';
export type StateFilter = 'all' | ImportCandidateState;
export type Translate = (key: string, params?: Record<string, string>) => string;

export interface ImportRow {
  readonly key: string;
  readonly testId: string;
  readonly state: ImportCandidateState;
  readonly name: string;
  readonly handle: string | null;
  readonly initials: string;
  readonly avatarUrl: string | null;
  readonly icon: string | null;
  readonly haystack: string;
  readonly stateLabel: string;
  readonly checkboxLabel: string;
}

export type RowsByTab = Readonly<Record<ImportTab, readonly ImportRow[]>>;
export type StateCounts = Record<StateFilter, number>;

export interface TallyQuery {
  readonly tabs: readonly ImportTab[];
  readonly current: ImportTab;
  readonly needle: string;
}

export interface Tally {
  readonly tabCounts: Readonly<Record<ImportTab, number>>;
  readonly stateCounts: Readonly<StateCounts>;
  readonly matched: readonly ImportRow[];
}

export const FILTER_KEYS: Readonly<Record<StateFilter, string>> = {
  all: 'settings.social.importStateAll',
  new: 'settings.social.importStateNew',
  subscribed: 'settings.social.importStateSubscribed',
  no_longer_followed: 'settings.social.importStateNoLongerFollowed',
};

const CHECKBOX_KEYS: Readonly<Record<ImportCandidateState, string>> = {
  new: 'settings.social.importSubscribe',
  subscribed: 'settings.social.importAlreadySubscribed',
  no_longer_followed: 'settings.social.importUnsubscribe',
};

const COLLECTION_CHECKBOX_KEYS: Readonly<Record<CollectionState, string>> = {
  new: 'settings.social.importAddCollection',
  subscribed: 'settings.social.importCollectionAdded',
};

const COLLECTION_ICONS: Readonly<Record<CollectionKind, string>> = {
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

export function followKey(follow: FollowCandidate): string {
  return `follow:${follow.feedUrl}`;
}

export function buildRows(candidates: ImportCandidates | null, translate: Translate): RowsByTab {
  const collectionRow = (collection: CollectionCandidate) => toCollectionRow(collection, translate);
  return {
    accounts: (candidates?.follows ?? []).map((follow) => toFollowRow(follow, translate)),
    lists: (candidates?.lists ?? []).map(collectionRow),
    customFeeds: (candidates?.customFeeds ?? []).map(collectionRow),
  };
}

export function tally(rows: RowsByTab, { tabs, current, needle }: TallyQuery): Tally {
  const tabCounts = { accounts: 0, lists: 0, customFeeds: 0 };
  const stateCounts: StateCounts = { all: 0, new: 0, subscribed: 0, no_longer_followed: 0 };
  const matched: ImportRow[] = [];
  for (const tab of tabs) {
    const hits = rows[tab].filter((row) => row.haystack.includes(needle));
    tabCounts[tab] = hits.length;
    if (tab !== current) continue;
    for (const row of hits) stateCounts[row.state]++;
    stateCounts.all = hits.length;
    matched.push(...hits);
  }
  return { tabCounts, stateCounts, matched };
}

function toFollowRow(follow: FollowCandidate, translate: Translate): ImportRow {
  return {
    key: followKey(follow),
    testId: `social-import-follow-${follow.identity}`,
    state: follow.state,
    name: follow.displayName ?? follow.handle,
    handle: follow.displayName === null ? null : follow.handle,
    initials: initialsOf(follow.displayName ?? follow.handle),
    avatarUrl: follow.avatarUrl,
    icon: null,
    haystack: `${follow.handle}\n${follow.displayName ?? ''}`.toLowerCase(),
    stateLabel: translate(FILTER_KEYS[follow.state]),
    checkboxLabel: translate(CHECKBOX_KEYS[follow.state], { handle: follow.handle }),
  };
}

function toCollectionRow(collection: CollectionCandidate, translate: Translate): ImportRow {
  const name =
    collection.kind === 'home' ? translate('settings.social.importHome') : collection.name;
  return {
    key: collectionKey(collection),
    testId: `social-import-collection-${collectionKey(collection)}`,
    state: collection.state,
    name,
    handle: null,
    initials: '',
    avatarUrl: null,
    icon: COLLECTION_ICONS[collection.kind],
    haystack: name.toLowerCase(),
    stateLabel: translate(FILTER_KEYS[collection.state]),
    checkboxLabel: translate(COLLECTION_CHECKBOX_KEYS[collection.state], { name }),
  };
}
