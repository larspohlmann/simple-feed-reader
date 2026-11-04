import { DestroyRef, Injectable, computed, effect, inject, untracked } from '@angular/core';
import { ActivatedRoute, Params, Router, convertToParamMap } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { ReaderApi } from '../reader-api';
import { EntryBodyService } from '../article/content/entry-body.service';
import { EntriesStore } from '../state/entries.store';
import { ListPreferences } from '../list/list-preferences.service';
import { selectionFromRoute } from '../query/reader-matcher';
import { isDirectSearch, sameSelection } from '../query/query';
import { AccountViewContext } from '../query/account-view-context';
import { signedUrlFromParam, sameSignedUrl } from '../query/link-param';
import { linkCardWithUrl } from '../link-card/link-card-with-url';
import { LinkReaderNavigation } from './link-reader-navigation.service';
import { TrendingWindowService } from '../trending/trending-window.service';
import { PreferencesService } from '../../core/preferences/preferences.service';

@Injectable()
export class ReaderRouteState {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly api = inject(ReaderApi);
  private readonly bodyService = inject(EntryBodyService);
  private readonly entries = inject(EntriesStore);
  private readonly listPreferences = inject(ListPreferences);
  private readonly linkNavigation = inject(LinkReaderNavigation);
  private readonly viewContext = inject(AccountViewContext);
  private readonly trendingWindow = inject(TrendingWindowService);
  private readonly preferences = inject(PreferencesService);

  private readonly queryParameters = toSignal(this.route.queryParamMap, {
    initialValue: convertToParamMap({}),
  });
  private readonly pathParameters = toSignal(this.route.paramMap, {
    initialValue: convertToParamMap({}),
  });
  private readonly parsed = computed(() =>
    selectionFromRoute(this.pathParameters(), this.queryParameters(), this.viewContext.context()),
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
    // Waits for the account, whose stored window a bare Trending link opens; only the
    // account's own switch, never a stale device cache, may take Trending out of the URL.
    effect(() => {
      const correction = this.parsed().urlCorrection;
      if (correction === null || !this.listPreferences.ready()) return;
      if (correction['view'] === null && !this.preferences.trendingConfirmed()) return;
      untracked(() => this.correctUrl(correction));
    });
    // A bare link opened the stored window, so only a window the URL names changes what is stored.
    effect(() => {
      const window = this.selection().window;
      if (window === undefined) return;
      untracked(() => this.trendingWindow.set(window));
    });
  }

  /** So the URL says what the parse opened. */
  private correctUrl(correction: Params): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: correction,
      queryParamsHandling: 'merge',
      replaceUrl: true,
    });
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
