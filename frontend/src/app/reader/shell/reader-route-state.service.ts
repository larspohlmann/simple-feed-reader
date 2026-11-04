import { DestroyRef, Injectable, computed, effect, inject, untracked } from '@angular/core';
import { ActivatedRoute, Router, convertToParamMap } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { ReaderApi } from '../reader-api';
import { EntryBodyService } from '../article/content/entry-body.service';
import { EntriesStore } from '../state/entries.store';
import { ListPreferences } from '../list/list-preferences.service';
import { selectionFromRoute } from '../query/reader-matcher';
import { OfferedViews, isDirectSearch, sameSelection } from '../query/query';
import { PreferencesService } from '../../core/preferences/preferences.service';
import { signedUrlFromParam, sameSignedUrl } from '../query/link-param';
import { linkCardWithUrl } from '../link-card/link-card-with-url';
import { LinkReaderNavigation } from './link-reader-navigation.service';
import { TrendingWindowService } from '../trending/trending-window.service';

@Injectable()
export class ReaderRouteState {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly api = inject(ReaderApi);
  private readonly bodyService = inject(EntryBodyService);
  private readonly entries = inject(EntriesStore);
  private readonly listPreferences = inject(ListPreferences);
  private readonly linkNavigation = inject(LinkReaderNavigation);
  private readonly preferences = inject(PreferencesService);
  private readonly trendingWindow = inject(TrendingWindowService);

  private readonly queryParameters = toSignal(this.route.queryParamMap, {
    initialValue: convertToParamMap({}),
  });
  private readonly pathParameters = toSignal(this.route.paramMap, {
    initialValue: convertToParamMap({}),
  });
  private readonly offered = computed<OfferedViews>(() => ({
    trending: this.preferences.trendingEnabled(),
  }));
  private readonly parsed = computed(() =>
    selectionFromRoute(this.pathParameters(), this.queryParameters(), this.offered()),
  );
  /** The URL names Trending on an account that switched it off; the parse already opened All items. */
  private readonly namesRefusedView = computed(
    () => this.queryParameters().get('view') === 'trending' && !this.offered().trending,
  );

  /** Structural equality: an entry-only URL change keeps the reference, so the list does not reload. */
  readonly selection = computed(() => this.listPreferences.appliedTo(this.parsed().selection), {
    equal: sameSelection,
  });
  private readonly inSearch = computed(() => isDirectSearch(this.parsed().selection));
  readonly entryId = computed(() => this.parsed().entryId);
  readonly linkTarget = computed(() => signedUrlFromParam(this.queryParameters().get('link')), {
    equal: sameSignedUrl,
  });

  /** The open link's card: the one just clicked, else the first listed card with its URL; null on a deep link to neither. */
  readonly openLinkCard = computed(() => {
    const target = this.linkTarget();
    if (target === null) return null;
    const opened = this.linkNavigation.opened();
    if (opened?.url === target.url) return opened;
    const open = this.openEntry();
    return (
      (open && linkCardWithUrl([open], target.url)) ??
      linkCardWithUrl(this.entries.entries(), target.url)
    );
  });

  readonly openEntry = computed(() => {
    const id = this.entryId();
    return id == null ? null : this.entries.entry(id);
  });
  /** Identity only: effects keyed on it fire once per opened entry, never on its flag changes. */
  readonly openEntryId = computed(() => this.openEntry()?.id ?? null);

  constructor() {
    inject(DestroyRef).onDestroy(() => this.entries.releaseUnlisted());
    effect(() => {
      if (this.inSearch()) return;
      untracked(() => this.listPreferences.endSearch());
    });
    effect(() => {
      const id = this.entryId();
      untracked(() => this.fetchUnlistedEntry(id));
    });
    effect(() => {
      if (!this.namesRefusedView()) return;
      untracked(() => this.dropViewFromUrl());
    });
    // The window last opened is the one the sidebar's Trending link and badge default to.
    effect(() => {
      const window = this.selection().window;
      if (window === undefined) return;
      untracked(() => this.trendingWindow.set(window));
    });
    // Warm the body store for the entries beside the one just opened. Keyed on
    // the id alone, so an unrelated list reload doesn't re-issue the prefetch.
    effect(() => {
      const id = this.openEntryId();
      if (id === null) return;
      untracked(() => this.prefetchNeighbours(id));
    });
  }

  /** So the URL agrees with the All items list the parse opened in its place. */
  private dropViewFromUrl(): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { view: null, window: null },
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
  }

  private prefetchNeighbours(id: number): void {
    const list = this.entries.entries();
    const index = list.findIndex((entry) => entry.id === id);
    if (index === -1) return;
    const previous = list[index - 1];
    const next = list[index + 1];
    if (previous) this.bodyService.prefetch(previous.id);
    if (next) this.bodyService.prefetch(next.id);
  }

  private fetchUnlistedEntry(id: number | null): void {
    if (id == null || this.isListed(id)) {
      this.entries.releaseUnlisted();
      return;
    }
    if (this.entries.entry(id)) return;
    this.entries.releaseUnlisted();
    this.api.entry(id).subscribe({
      // Id-guarded: a slow answer for an abandoned deep link must not replace the entry now open.
      next: (response) => {
        if (this.entryId() !== id) return;
        this.entries.holdUnlisted(response.entry);
        this.bodyService.seed(response.entry.id, response.entry);
      },
    });
  }

  private isListed(id: number): boolean {
    return this.entries.entries().some((entry) => entry.id === id);
  }
}
