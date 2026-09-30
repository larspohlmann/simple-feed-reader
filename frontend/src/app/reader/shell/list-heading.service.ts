import { Injectable, computed, inject } from '@angular/core';
import { TranslocoService } from '@jsverse/transloco';
import { LanguageService } from '../../core/language.service';
import { SubscriptionsStore } from '../subscriptions.store';
import { EntriesStore } from '../entries.store';
import { RecommendationsService } from '../recommendations.service';
import { SavedSearchesStore } from '../saved-searches.store';
import { ReadingLayoutService } from '../reading-layout.service';
import { Selection, visibleSearchTerm } from '../query';
import { TitleCount } from '../entry-list/entry-list.component';
import { ReaderRouteState } from './reader-route-state.service';

@Injectable()
export class ListHeading {
  private readonly routeState = inject(ReaderRouteState);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly entries = inject(EntriesStore);
  private readonly recommendations = inject(RecommendationsService);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly layout = inject(ReadingLayoutService);
  private readonly language = inject(LanguageService);
  private readonly i18n = inject(TranslocoService);

  readonly activeSavedSearchId = computed(() => {
    const selection = this.routeState.selection();
    return selection.kind === 'saved-search' ? selection.id : null;
  });

  readonly activeSavedSearch = computed(() => {
    const id = this.activeSavedSearchId();
    if (id === null) return null;
    return this.savedSearches.savedSearches().find((saved) => saved.id === id) ?? null;
  });

  private readonly selectedTagNode = computed(() => {
    const selection = this.routeState.selection();
    if (selection.kind !== 'tag') return null;
    return this.subscriptions.tagTree().find((node) => node.tag.id === selection.id) ?? null;
  });

  readonly selectedTag = computed(() => this.selectedTagNode()?.tag ?? null);

  readonly selectedSubscription = computed(() => {
    const selection = this.routeState.selection();
    if (selection.kind !== 'subscription') return null;
    return (
      this.subscriptions.subscriptions().find((subscription) => subscription.id === selection.id) ??
      null
    );
  });

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

  readonly hasMore = computed(() => this.entries.nextCursor() !== null);

  /** A search request in flight, not merely any list loading. */
  readonly searching = computed(
    () => this.routeState.selection().kind === 'search' && this.entries.loading(),
  );

  readonly lastRefreshed = computed(() => {
    if (this.routeState.selection().kind === 'for-you') return this.recommendations.generatedAt();
    return this.selectedSubscription()?.lastFetchedAt ?? null;
  });

  readonly nextRefresh = computed(() => this.selectedSubscription()?.nextFetchAt ?? null);

  readonly newestRunId = computed(() =>
    this.routeState.selection().kind === 'for-you' ? this.recommendations.newestRunId() : null,
  );

  readonly title = computed(() => {
    // translate() is one-shot: reading the language re-runs this on a switch.
    this.language.lang();
    const selection = this.routeState.selection();
    // No default: a new selection kind must fail to compile here.
    switch (selection.kind) {
      case 'favorites':
        return this.i18n.translate('reader.favorites');
      case 'kept':
        return this.i18n.translate('reader.kept');
      case 'viewed':
        return this.i18n.translate('reader.viewed');
      case 'for-you':
        return this.i18n.translate('reader.forYou');
      case 'saved-searches':
        return this.i18n.translate('reader.savedSearches');
      case 'saved-search':
        return this.activeSavedSearch()?.term ?? this.i18n.translate('reader.savedSearches');
      case 'all':
        return this.i18n.translate('reader.allItems');
      case 'tag':
        return this.selectedTag()?.name ?? this.i18n.translate('reader.tagFallback');
      case 'search':
        return `${this.searchTitlePrefix()} ${this.searchTitleBody()}`;
      case 'subscription':
        return this.selectedSubscription()?.title ?? this.i18n.translate('reader.feedFallback');
    }
  });

  readonly titleCount = computed<TitleCount>(() => {
    const selection = this.routeState.selection();
    switch (selection.kind) {
      case 'all':
        return bySwitch(
          selection,
          this.subscriptions.totalUnread(),
          this.subscriptions.totalEntries(),
        );
      case 'tag': {
        const node = this.selectedTagNode();
        return bySwitch(selection, node?.unreadCount ?? 0, node?.entryCount ?? 0);
      }
      case 'subscription': {
        const subscription = this.selectedSubscription();
        return bySwitch(selection, subscription?.unreadCount ?? 0, subscription?.entryCount ?? 0);
      }
      case 'favorites':
        return items(this.subscriptions.favoritesCount());
      case 'kept':
        return items(this.subscriptions.keptCount());
      case 'viewed':
        return items(this.subscriptions.viewedCount());
      case 'for-you':
        return bySwitch(
          selection,
          this.recommendations.forYouCount(),
          this.recommendations.forYouTotal(),
        );
      case 'saved-searches':
        return bySwitch(selection, this.savedSearchesUnread(), this.savedSearchesTotal());
      case 'saved-search': {
        const saved = this.activeSavedSearch();
        return bySwitch(selection, saved?.unreadCount ?? 0, saved?.memberCount ?? 0);
      }
      case 'search':
        return items(0);
    }
  });

  readonly searchTitlePrefix = computed(() => {
    this.language.lang();
    return this.i18n.translate('reader.searchResultsPrefix');
  });

  readonly searchTitleBody = computed(() => {
    this.language.lang();
    const term = this.searchTerm();
    // In flight, the previous term's rows are still on screen, so no count yet.
    if (this.searching()) return this.i18n.translate('reader.searchResults', { term });
    const count = this.entries.entries().length;
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
    const count = this.entries.entries().length;
    return this.hasMore() ? `${count}+` : `${count}`;
  });

  private readonly searchTerm = computed(() =>
    visibleSearchTerm(this.routeState.selection().term ?? ''),
  );

  /** A post matching two searches counts twice, so the heading matches the sidebar row. */
  private readonly savedSearchesUnread = computed(() =>
    this.savedSearches.savedSearches().reduce((sum, saved) => sum + saved.unreadCount, 0),
  );

  private readonly savedSearchesTotal = computed(() =>
    this.savedSearches.savedSearches().reduce((sum, saved) => sum + saved.memberCount, 0),
  );
}

function unread(value: number): TitleCount {
  return { value, counts: 'unread' };
}

function items(value: number): TitleCount {
  return { value, counts: 'items' };
}

/** The unread count under "Only unread", every post under "All posts". */
function bySwitch(selection: Selection, unreadCount: number, allCount: number): TitleCount {
  return selection.unread ? unread(unreadCount) : items(allCount);
}
