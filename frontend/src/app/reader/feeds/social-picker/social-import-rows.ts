import {
  CollectionCandidate,
  CollectionKind,
  FollowCandidate,
  ImportCandidateState,
  ImportCandidates,
} from './social-import.service';

export type ImportTab = 'accounts' | 'lists' | 'customFeeds' | 'tags';
export type StateFilter = 'all' | ImportCandidateState;
export type Translate = (key: string, params?: Record<string, string>) => string;

export interface ImportRow {
  readonly key: string;
  readonly testId: string;
  readonly state: ImportCandidateState;
  readonly name: string;
  readonly handle: string | null;
  readonly avatarUrl: string | null;
  readonly icon: string | null;
  readonly haystack: string;
  readonly stateLabel: string;
  readonly checkboxLabel: string;
  readonly removable: boolean;
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

const COLLECTION_ICONS: Readonly<Record<CollectionKind, string>> = {
  home: 'home',
  trending: 'trending_up',
  list: 'list',
  custom_feed: 'dynamic_feed',
  tag: 'tag',
};

const SINGLETON_NAME_KEYS: Readonly<Partial<Record<CollectionKind, string>>> = {
  home: 'settings.social.importHome',
  trending: 'settings.social.importTrending',
};

export const TAB_TEST_IDS: Readonly<Record<ImportTab, string>> = {
  accounts: 'social-import-follows',
  lists: 'social-import-lists',
  customFeeds: 'social-import-feeds',
  tags: 'social-import-tags',
};

/** Home has no id, and a list and a custom feed could share one, so the kind is part of the key. */
export function collectionKey(collection: CollectionCandidate): string {
  return `${collection.kind}:${collection.id ?? ''}`;
}

export function collectionsOf(candidates: ImportCandidates | null): readonly CollectionCandidate[] {
  return [
    ...(candidates?.lists ?? []),
    ...(candidates?.customFeeds ?? []),
    ...(candidates?.tags ?? []),
  ];
}

/** An already-subscribed row can be unticked to remove it. */
export function isSubscribed(state: ImportCandidateState): boolean {
  return state !== 'new';
}

export interface Removal {
  readonly name: string;
  readonly subscriptionId: number;
}

/** The subscribed rows the user unticked, follows first. */
export function removals(
  candidates: ImportCandidates | null,
  chosen: ReadonlySet<string>,
  translate: Translate,
): Removal[] {
  const follows = (candidates?.follows ?? [])
    .filter((follow) => isSubscribed(follow.state) && chosen.has(followKey(follow)))
    .map((follow) => ({
      name: followName(follow),
      subscriptionId: follow.subscriptionId,
    }));
  const collections = collectionsOf(candidates)
    .filter((collection) => isSubscribed(collection.state) && chosen.has(collectionKey(collection)))
    .map((collection) => ({
      name: collectionName(collection, translate),
      subscriptionId: collection.subscriptionId,
    }));
  return [...follows, ...collections].flatMap(({ name, subscriptionId }) =>
    subscriptionId === null ? [] : [{ name, subscriptionId }],
  );
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
    tags: (candidates?.tags ?? []).map(collectionRow),
  };
}

export function tally(rows: RowsByTab, { tabs, current, needle }: TallyQuery): Tally {
  const tabCounts = { accounts: 0, lists: 0, customFeeds: 0, tags: 0 };
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
  const removable = isSubscribed(follow.state);
  return {
    key: followKey(follow),
    testId: `social-import-follow-${follow.identity}`,
    state: follow.state,
    name: followName(follow),
    handle: follow.displayName === null ? null : follow.handle,
    avatarUrl: follow.avatarUrl,
    icon: null,
    haystack: `${follow.handle}\n${follow.displayName ?? ''}`.toLowerCase(),
    stateLabel: translate(FILTER_KEYS[follow.state]),
    checkboxLabel: translate(
      removable ? 'settings.social.importUnsubscribe' : 'settings.social.importSubscribe',
      { handle: follow.handle },
    ),
    removable,
  };
}

function followName(follow: FollowCandidate): string {
  return follow.displayName ?? follow.handle;
}

function collectionName(collection: CollectionCandidate, translate: Translate): string {
  const key = SINGLETON_NAME_KEYS[collection.kind];
  return key === undefined ? collection.name : translate(key);
}

function toCollectionRow(collection: CollectionCandidate, translate: Translate): ImportRow {
  const name = collectionName(collection, translate);
  const removable = isSubscribed(collection.state);
  return {
    key: collectionKey(collection),
    testId: `social-import-collection-${collectionKey(collection)}`,
    state: collection.state,
    name,
    handle: null,
    avatarUrl: null,
    icon: COLLECTION_ICONS[collection.kind],
    haystack: name.toLowerCase(),
    stateLabel: translate(FILTER_KEYS[collection.state]),
    checkboxLabel: translate(
      removable ? 'settings.social.importRemoveCollection' : 'settings.social.importAddCollection',
      { name },
    ),
    removable,
  };
}
