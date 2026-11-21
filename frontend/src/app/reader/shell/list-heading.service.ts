import { Injectable, computed, inject } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { LanguageService } from '../../core/i18n/language.service';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { FollowedStoriesStore } from '../state/followed-stories.store';
import { OpenStory } from '../state/open-story';
import { RecommendationsService } from '../state/recommendations.service';
import { SavedSearchesStore } from '../state/saved-searches.store';
import { VisibilitySwitchesStore } from '../state/visibility-switches.store';
import { sumEntries } from '../state/subscription-derivations';
import { ReadingLayoutService } from '../reading-layout.service';
import {
  BySelectionKind,
  FilteredSelection,
  SelectionOf,
  bySelectionKind,
  searchTermOf,
  selectedSavedSearchIdOf,
  visibleSearchTerm,
} from '../query/query';
import { TitleCount } from '../list/list-header/list-header.component';
import { isUnrenamedTimeline } from '../feeds/display-title';
import { SubscriptionDto } from '../models';
import { FeedGroupName } from '../../core/feed-group';
import { SocialNetworkName } from '../../core/social-network';
import { TrendingBuiltAt } from '../trending/trending-built-at';
import { TrendingWindowService } from '../trending/trending-window.service';
import { ListContext } from '../state/list-context';
import { networkFeedKindLabels, SocialSection, feedAvatarUrl } from '../state/social-sections';

/** The title, counts and refresh times heading one host's list (#2329). */
@Injectable()
export class ListHeading {
  private readonly list = inject(ListContext);
  private readonly selection = this.list.selection;
  private readonly entries = this.list.entries;
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly openStory = inject(OpenStory);
  private readonly followedStories = inject(FollowedStoriesStore);
  private readonly recommendations = inject(RecommendationsService);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly layout = inject(ReadingLayoutService);
  private readonly language = inject(LanguageService);
  private readonly i18n = inject(TranslocoService);
  private readonly visibilitySwitches = inject(VisibilitySwitchesStore);
  private readonly trendingWindow = inject(TrendingWindowService);
  private readonly trendingBuiltAt = inject(TrendingBuiltAt);

  private readonly allItemsEntries = computed(() =>
    sumEntries(this.subscriptions.subscriptions(), this.visibilitySwitches.switches()),
  );

  readonly activeSavedSearchId = computed(() => selectedSavedSearchIdOf(this.selection()));

  readonly activeSavedSearch = computed(() => {
    const id = this.activeSavedSearchId();
    if (id === null) return null;
    return this.savedSearches.savedSearches().find((saved) => saved.id === id) ?? null;
  });

  private readonly selectedTagNode = computed(() => {
    const selection = this.selection();
    if (selection.kind !== 'tag') return null;
    return this.subscriptions.tagNodeById().get(selection.id) ?? null;
  });

  readonly selectedTag = computed(() => this.selectedTagNode()?.tag ?? null);

  readonly selectedSubscription = computed(() => {
    const selection = this.selection();
    if (selection.kind !== 'subscription') return null;
    return (
      this.subscriptions.subscriptions().find((subscription) => subscription.id === selection.id) ??
      null
    );
  });

  readonly titleAvatarUrl = computed(() => feedAvatarUrl(this.selectedSubscription()));

  /** Magazine only: in the dense list layout the intro would be a slab above the rows. */
  readonly feedIntroSubscription = computed(() => {
    if (this.layout.mode() !== 'magazine') return null;
    const subscription = this.selectedSubscription();
    if (subscription === null) return null;
    const hasIntro =
      subscription.description !== null ||
      subscription.imageUrl !== null ||
      subscription.siteUrl !== null;
    return hasIntro ? subscription : null;
  });

  readonly feedLanguage = computed(() => this.selectedSubscription()?.language ?? null);

  readonly feedIntroTitle = computed(() => {
    const subscription = this.feedIntroSubscription();
    return subscription?.title ?? null;
  });

  readonly hasMore = computed(() => this.entries.nextCursor() !== null);

  /** A search request in flight, not merely any list loading. */
  readonly searching = computed(() => this.selection().kind === 'search' && this.entries.loading());

  private readonly feedFetchedAt = () => this.selectedSubscription()?.lastFetchedAt ?? null;

  private readonly lastRefreshedByKind: BySelectionKind<string | null> = {
    'for-you': () => this.recommendations.generatedAt(),
    trending: () => this.trendingBuiltAt.at(this.trendingWindow.window()),
    notifications: () => null,
    favorites: this.feedFetchedAt,
    kept: this.feedFetchedAt,
    shared: this.feedFetchedAt,
    viewed: this.feedFetchedAt,
    'saved-searches': this.feedFetchedAt,
    'saved-search': this.feedFetchedAt,
    'trending-story': this.feedFetchedAt,
    all: this.feedFetchedAt,
    tag: this.feedFetchedAt,
    network: this.feedFetchedAt,
    group: this.feedFetchedAt,
    search: this.feedFetchedAt,
    subscription: this.feedFetchedAt,
  };

  readonly lastRefreshed = computed(() =>
    bySelectionKind(this.lastRefreshedByKind, this.selection()),
  );

  readonly nextRefresh = computed(() => this.selectedSubscription()?.nextFetchAt ?? null);

  readonly newestRunId = computed(() =>
    this.selection().kind === 'for-you' ? this.recommendations.newestRunId() : null,
  );

  /** Keyed by every kind: a new selection kind must fail to compile here. */
  private readonly titleByKind: BySelectionKind<string> = {
    favorites: () => this.i18n.translate('reader.favorites'),
    kept: () => this.i18n.translate('reader.kept'),
    shared: () => this.i18n.translate('reader.shared'),
    viewed: () => this.i18n.translate('reader.viewed'),
    'for-you': () => this.i18n.translate('reader.forYou'),
    trending: (selection) =>
      `${this.i18n.translate('reader.trending')} · ${this.i18n.translate(`reader.trendingWindow.${selection.window}`)}`,
    notifications: () => this.i18n.translate('reader.notifications.title'),
    'saved-searches': () => this.i18n.translate('reader.savedSearches'),
    'saved-search': () =>
      this.activeSavedSearch()?.term ?? this.i18n.translate('reader.savedSearches'),
    'trending-story': (selection) =>
      this.openStory.cardOf(selection.id)?.title ??
      this.followedStories.byId(selection.id)?.headline ??
      this.i18n.translate('reader.storyFallback'),
    all: () => this.i18n.translate('reader.allItems'),
    tag: () => this.selectedTag()?.name ?? this.i18n.translate('reader.tagFallback'),
    network: (selection) => this.networkTitle(selection),
    group: (selection) => this.i18n.translate(GROUP_TITLE_KEY[selection.group]),
    search: () => `${this.searchTitlePrefix()} ${this.searchTitleBody()}`,
    subscription: () => {
      const subscription = this.selectedSubscription();
      return subscription
        ? this.feedTitle(subscription)
        : this.i18n.translate('reader.feedFallback');
    },
  };

  private networkTitle(selection: SelectionOf<'network'>): string {
    const network = this.networkName(selection.network);
    return selection.feedKind
      ? `${network} · ${this.i18n.translate(networkFeedKindLabels(selection.feedKind, selection.network).labelKey)}`
      : network;
  }

  private networkName(network: SocialNetworkName): string {
    return this.i18n.translate(`settings.social.${network}`);
  }

  private feedTitle(subscription: SubscriptionDto): string {
    const handle = this.sharedNetworkHandle(subscription);
    return handle !== null && isUnrenamedTimeline(subscription)
      ? `${subscription.title} · ${handle}`
      : subscription.title;
  }

  private sharedNetworkHandle(subscription: SubscriptionDto): string | null {
    const social = subscription.social;
    const section = social ? this.sectionOf(social.network) : null;
    if (!social || !section?.showsHandles) return null;
    return section.accounts.find((group) => group.accountId === social.accountId)?.handle ?? null;
  }

  private sectionOf(network: SocialNetworkName): SocialSection | null {
    return (
      this.subscriptions.socialSections().find((section) => section.network === network) ?? null
    );
  }

  private networkCounts(
    selection: SelectionOf<'network'>,
  ): Pick<SocialSection, 'unreadCount' | 'entryCount'> {
    const section = this.sectionOf(selection.network);
    if (section === null) return { unreadCount: 0, entryCount: 0 };
    return selection.feedKind ? section.groups[selection.feedKind] : section;
  }

  private readonly countByKind: BySelectionKind<TitleCount> = {
    all: (selection) =>
      bySwitch(selection, this.subscriptions.totalUnread(), this.allItemsEntries()),
    tag: (selection) => {
      const node = this.selectedTagNode();
      return bySwitch(selection, node?.unreadCount ?? 0, node?.entryCount ?? 0);
    },
    network: (selection) => {
      const counts = this.networkCounts(selection);
      return bySwitch(selection, counts.unreadCount, counts.entryCount);
    },
    group: (selection) => {
      const counts = this.subscriptions.feedGroupCounts()[selection.group];
      return bySwitch(selection, counts.unreadCount, counts.entryCount);
    },
    subscription: (selection) => {
      const subscription = this.selectedSubscription();
      return bySwitch(selection, subscription?.unreadCount ?? 0, subscription?.entryCount ?? 0);
    },
    favorites: () => items(this.subscriptions.favoritesCount()),
    kept: () => items(this.subscriptions.keptCount()),
    shared: () => items(this.subscriptions.sharedCount()),
    viewed: () => items(this.subscriptions.viewedCount()),
    'for-you': (selection) =>
      bySwitch(selection, this.subscriptions.forYouUnread(), this.recommendations.forYouTotal()),
    trending: () => items(this.entries.items().length),
    notifications: () => items(0),
    'saved-searches': (selection) =>
      bySwitch(selection, this.savedSearchesUnread(), this.savedSearchesTotal()),
    'saved-search': (selection) => {
      const saved = this.activeSavedSearch();
      return bySwitch(selection, saved?.unreadCount ?? 0, saved?.memberCount ?? 0);
    },
    'trending-story': (selection) => {
      const tally = this.openStory.tallyOf(selection.id);
      return bySwitch(selection, tally?.unreadCount ?? 0, tally?.memberCount ?? 0);
    },
    search: () => items(0),
  };

  readonly title = computed(() => {
    // translate() is one-shot: reading the language re-runs this on a switch.
    this.language.lang();
    return bySelectionKind(this.titleByKind, this.selection());
  });

  /** The tab carries no network logo, so it names the network the header shows as one. */
  readonly tabTitle = computed(() => {
    const selection = this.selection();
    if (selection.kind !== 'notifications') return this.title();
    return `${this.networkName(selection.network)} · ${this.title()}`;
  });

  readonly titleCount = computed<TitleCount>(() =>
    bySelectionKind(this.countByKind, this.selection()),
  );

  readonly searchTitlePrefix = computed(() => {
    this.language.lang();
    return this.i18n.translate('reader.searchResultsPrefix');
  });

  readonly searchTitleBody = computed(() => {
    this.language.lang();
    const term = this.searchTerm();
    // In flight, the previous term's rows are still on screen, so no count yet.
    if (this.searching()) return this.i18n.translate('reader.searchResults', { term });
    const count = this.entries.items().length;
    const key = this.hasMore() ? 'reader.searchResultsCountMore' : 'reader.searchResultsCount';
    return this.i18n.translate(key, { term, count });
  });

  readonly searchTitleTerm = computed(() => {
    this.language.lang();
    return this.i18n.translate('reader.searchResults', { term: this.searchTerm() });
  });

  /** The loaded count, not a total: a trailing '+' while another page is out there. */
  readonly searchCountLabel = computed<string | null>(() => {
    if (this.searching()) return null;
    const count = this.entries.items().length;
    return this.hasMore() ? `${count}+` : `${count}`;
  });

  private readonly searchTerm = computed(() => visibleSearchTerm(searchTermOf(this.selection())));

  /** A post matching two searches counts twice, so the heading matches the sidebar row. */
  private readonly savedSearchesUnread = computed(() =>
    this.savedSearches.savedSearches().reduce((sum, saved) => sum + saved.unreadCount, 0),
  );

  private readonly savedSearchesTotal = computed(() =>
    this.savedSearches.savedSearches().reduce((sum, saved) => sum + saved.memberCount, 0),
  );
}

const GROUP_TITLE_KEY: Record<FeedGroupName, string> = {
  tags: 'reader.tags',
  feeds: 'reader.feeds',
};

function unread(value: number): TitleCount {
  return { value, counts: 'unread' };
}

function items(value: number): TitleCount {
  return { value, counts: 'items' };
}

/** The unread count under "Only unread", every post under "All posts". */
function bySwitch(selection: FilteredSelection, unreadCount: number, allCount: number): TitleCount {
  return selection.unread ? unread(unreadCount) : items(allCount);
}
