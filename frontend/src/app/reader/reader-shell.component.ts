// src/app/reader/reader-shell.component.ts
import {
  AfterViewInit,
  Component,
  ElementRef,
  OnDestroy,
  OnInit,
  computed,
  effect,
  inject,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { ActivatedRoute, Router, RouterLink, convertToParamMap } from '@angular/router';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of } from 'rxjs';
import { Dialog } from '@angular/cdk/dialog';
import { AuthService } from '../core/auth.service';
import { PageTitleService } from '../core/page-title.service';
import { isPasskeySupported } from '../core/webauthn';
import { LanguageService } from '../core/language.service';
import { ReaderApi } from './reader-api';
import { SubscriptionsStore } from './subscriptions.store';
import { TagsStore } from './tags.store';
import { EntriesStore, localStatePatch } from './entries.store';
import { RefreshService } from './refresh.service';
import { RecommendationsService } from './recommendations.service';
import { SavedSearchesStore } from './saved-searches.store';
import { refreshFailureKey } from './refresh-message';
import { AiAvailabilityService } from '../core/ai-availability.service';
import { DigestService } from '../core/digest.service';
import { VersionService } from '../core/version.service';
import { ReadingLayoutService } from './reading-layout.service';
import { LayoutService } from './layout.service';
import {
  RefreshScope,
  Selection,
  isWholeWordTerm,
  isPhraseTerm,
  MarkReadTarget,
  markReadTarget,
  queryFromSelection,
  sameSelection,
  selectionFromParams,
  selectionQueryParams,
  visibleSearchTerm,
} from './query';
import { ListScrollReset } from './list-scroll-reset';
import { entryParam } from './slug';
import {
  EntryDto,
  EntryStatePatch,
  SavedSearchDto,
  SubscriptionDto,
  SubscriptionTagDto,
  TagDto,
} from './models';
import { ReaderHeaderComponent } from './header/reader-header.component';
import { SidebarComponent } from './sidebar/sidebar.component';
import { EntryListComponent, TitleCount } from './entry-list/entry-list.component';
import { ReaderViewComponent } from './reader-view/reader-view.component';
import { AddFeedDialogComponent } from './add-feed/add-feed-dialog.component';
import {
  ConfirmData,
  ConfirmDialogComponent,
} from '../shared/confirm-dialog/confirm-dialog.component';
import { ActionSheet } from '../shared/action-sheet/action-sheet.service';
import { ManageActions } from './manage/manage-actions.service';
import { DrawerSwipeDirective } from './drawer-swipe.directive';
import { SidebarCountsPoll } from './sidebar-counts-poll.service';
import { CatalogStore } from '../discover/catalog.store';
import { OnboardingSkip } from '../discover/onboarding-skip';
import { SetupService } from '../setup/setup.service';
import { IconComponent } from '../shared/icon/icon.component';
import { ListActionDirective } from '../shared/list-action/list-action.directive';
import { ButtonComponent } from '../shared/button/button.component';
import { FeedIntroComponent } from './feed-intro/feed-intro.component';
import { PasskeyOfferDialogComponent } from './passkey-offer-dialog.component';
import { CONFIRMATION_DURATION_MS, ToastService } from '../shared/toast/toast.service';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';

@Component({
  selector: 'app-reader-shell',
  imports: [
    ReaderHeaderComponent,
    SidebarComponent,
    EntryListComponent,
    ReaderViewComponent,
    DrawerSwipeDirective,
    IconComponent,
    ListActionDirective,
    ButtonComponent,
    FeedIntroComponent,
    RouterLink,
    TranslocoPipe,
  ],
  templateUrl: './reader-shell.component.html',
  styleUrl: './reader-shell.component.scss',
  // Provided here, not in the root injector, so the poll cannot outlive the
  // reader that it keeps up to date (#708).
  providers: [SidebarCountsPoll],
})
export class ReaderShellComponent implements OnInit, AfterViewInit, OnDestroy {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly dialog = inject(Dialog);
  private readonly toast = inject(ToastService);
  private readonly actionSheet = inject(ActionSheet);
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);
  private readonly api = inject(ReaderApi);
  protected readonly auth = inject(AuthService);
  private readonly hostRef = inject(ElementRef<HTMLElement>);

  readonly manage = inject(ManageActions);
  readonly subs = inject(SubscriptionsStore);
  readonly tags = inject(TagsStore);
  readonly entries = inject(EntriesStore);
  readonly refreshSvc = inject(RefreshService);
  readonly recs = inject(RecommendationsService);
  readonly savedSearchesStore = inject(SavedSearchesStore);
  readonly ai = inject(AiAvailabilityService);
  readonly digest = inject(DigestService);
  private readonly versions = inject(VersionService);
  readonly layout = inject(ReadingLayoutService);
  readonly screen = inject(LayoutService);
  private readonly skip = inject(OnboardingSkip);
  private readonly catalog = inject(CatalogStore);
  private readonly pageTitle = inject(PageTitleService);
  private readonly setup = inject(SetupService);
  /** Injected for its effect: it watches navigations so that a clicked list
   *  starts at the top while a list returned to keeps its place (#286). The
   *  reader is the only place that imports it, which is what keeps it out of
   *  the initial bundle. */
  private readonly listScrollReset = inject(ListScrollReset);
  /** Injected for its timer: it keeps every count on screen — the sidebar
   *  badges, the list heading and the tab title, which all read the same two
   *  stores (#709) — moving on its own while the reader is open (#708).
   *  Holding it is what starts it, and dropping it is what stops it. */
  private readonly countsPoll = inject(SidebarCountsPoll);

  /** Is the picker worth showing at all? Nothing seeds the catalog — it arrives
   *  by admin import — so a deployment without one must not redirect anybody
   *  into a blank page. */
  private readonly onboardingAvailable = computed(
    () => this.catalog.resolved() && this.catalog.hasEntries(),
  );

  /** Admins get the catalog resolved unconditionally — they are the only ones
   *  who can fix an empty one, and the suppressed onboarding is otherwise
   *  invisible. One cached request per session. */
  private readonly loadCatalogForAdmin = effect(() => {
    if (this.auth.isAdmin()) untracked(() => this.catalog.load());
  });

  readonly showCatalogEmptyWarning = computed(
    () => this.auth.isAdmin() && this.catalog.resolved() && !this.catalog.hasEntries(),
  );

  /** A brand-new subscription set: rows exist, none has ever been fetched. This
   *  is what a just-completed onboarding looks like from the shell's side. */
  private readonly awaitingFirstFetch = computed(
    () =>
      this.subs.resolved() &&
      this.subs.subscriptions().length > 0 &&
      this.subs.subscriptions().every((s) => s.lastFetchedAt === null),
  );

  private readonly sweptOnce = signal(false);
  /** True only for the span of the post-onboarding sweep — set when it fires,
   *  cleared once it lands without error. `sweptOnce` is a permanent one-way
   *  latch, so gating the banner on it would re-show the counted banner on every
   *  later refresh (sidebar button, scoped, add-feed) over an already-populated
   *  list. This sweep-scoped flag is what keeps the banner to the sweep alone. */
  private readonly sweeping = signal(false);

  /** The counted banner belongs to the post-onboarding sweep only. Every other
   *  refresh has the hairline, which is enough context for a user who already
   *  knows what their reader looks like. A failure takes the strip over, so the
   *  two never compete for it. */
  readonly showFetchProgress = computed(
    () => this.sweeping() && this.refreshSvc.failure() === null,
  );

  /** An empty subscription list, once resolved, that has not been explicitly
   *  skipped this session -- the redirect effect further down's own guard
   *  before it decides whether the catalog makes the picker worth showing.
   *  Extracted so #624's onboarding guard below can read the identical
   *  condition instead of re-deriving it (`onboardingAvailable` is already
   *  its own shared computed; this is the other half of that effect's
   *  guard).
   *
   *  A failed load resolves with an empty list too; that is not "zero
   *  subscriptions" but "we could not read them", so `!this.subs.error()`
   *  keeps a retry-able failure from reading as onboarding, on both sides
   *  that read this (#691). */
  private readonly emptySubscriptionsNeedingOnboarding = computed(
    () =>
      this.subs.resolved() &&
      !this.subs.error() &&
      this.subs.subscriptions().length === 0 &&
      !this.skip.wasSkipped(),
  );

  /** #624: true while a new account's subscriptions are being introduced --
   *  about to be redirected to /discover, or, having just come back from
   *  there, living through the post-onboarding first-fetch sweep above. A
   *  modal offered on top of either window steps on the onboarding (design
   *  spec §5.3), so the first-login passkey offer waits for both to clear.
   *
   *  Fix round 1 (a real defect this shipped with, hitting every brand-new
   *  account): an empty list alone is not enough to rule the redirect out.
   *  The redirect effect only starts the catalog request once subscriptions
   *  resolve empty, so there is a real window where the list is empty and
   *  `onboardingAvailable()` still reads false purely because the catalog
   *  hasn't answered YET -- not because there is nothing to onboard from.
   *  Reading `!catalog.resolved()` as "onboarding running" too closes that
   *  window: the guard now stays true for an empty list from the moment it
   *  resolves until the redirect decision has actually been made one way or
   *  the other (catalog resolved with entries -> the redirect fires and this
   *  stays true forever while the list is empty; catalog resolved empty ->
   *  there is nothing to redirect to, and this correctly falls open). */
  private readonly subscriptionOnboardingRunning = computed(() => {
    if (this.awaitingFirstFetch() || this.sweeping()) return true;
    if (!this.emptySubscriptionsNeedingOnboarding()) return false;
    if (!this.catalog.resolved()) return true;
    return this.onboardingAvailable();
  });

  /** #624: the shell has loaded enough real state to judge the passkey offer
   *  -- the subscriptions resolved, which is what lets `subscriptionOnboardingRunning`
   *  above give a real answer instead of the "nothing decided yet" default. The
   *  signed-in user is checked in `passkeyOfferEligible` below, which returns
   *  before ever reaching this -- rechecking it here would be dead code. */
  private readonly readerSettled = computed(() => this.subs.resolved());

  /** #624 design spec §5.3: all four conditions the first-login passkey
   *  offer needs before it may show. The fourth (on the reader, not an auth
   *  route) needs no check here -- this component exists only on the reader
   *  route (`app.routes.ts`), never on an auth screen. `isPasskeySupported()`
   *  is checked first because it is the cheapest -- false for nearly every
   *  test in this suite, since jsdom carries no `PublicKeyCredential` -- so a
   *  user fixture without `preferences` set never has that field touched.
   *
   *  #624 follow-up adds a fifth: `SetupService.passkeySignInAvailable()`
   *  must be exactly `true`. Offering enrolment while the instance cannot
   *  complete a passkey sign-in would hand the account a credential it can
   *  never use -- the same reasoning `PasskeysGroupComponent.visible` gives
   *  for failing CLOSED rather than open. This route is never behind
   *  `setupRedirectGuard`, so the constructor below triggers the identical
   *  `ensureLoaded()` that guard runs. */
  private readonly passkeyOfferEligible = computed(() => {
    if (!isPasskeySupported()) return false;
    if (this.setup.passkeySignInAvailable() !== true) return false;
    const user = this.auth.user();
    if (!user || user.preferences.passkeyOfferAnswered) return false;
    return this.readerSettled() && !this.subscriptionOnboardingRunning();
  });

  /** Latches true the moment the offer opens so a later re-render -- the
   *  eligibility computed re-evaluating true again before the answer has
   *  round-tripped to the server -- cannot open a second one in the same
   *  boot (design spec §5.3/§5.4). Never reset: the offer is a once-per-boot
   *  event, not a once-per-condition one. */
  private readonly passkeyOfferShown = signal(false);

  /** Opens the first-login passkey offer at most once per boot (#624). The
   *  dialog owns everything about what happens next -- both ceremonies, both
   *  states, and marking the offer answered on every way out -- so this
   *  effect's only job is deciding when. */
  private readonly offerPasskeyOnFirstBoot = effect(() => {
    if (!this.passkeyOfferEligible() || this.passkeyOfferShown()) return;
    untracked(() => {
      this.passkeyOfferShown.set(true);
      this.dialog.open<void>(PasskeyOfferDialogComponent, { panelClass: 'app-dialog' });
    });
  });

  /** What to tell the user about a refresh that fetched nothing, from ANY
   *  refresh — not just the sweep. Gating this on the sweep window is what left
   *  a failed sidebar refresh, scoped refresh or add-feed silent (#119). */
  readonly fetchFailureKey = computed(() => {
    const failure = this.refreshSvc.failure();
    return failure ? refreshFailureKey(failure) : null;
  });

  private readonly params = toSignal(this.route.queryParamMap, {
    initialValue: convertToParamMap({}),
  });
  private readonly parsed = computed(() => selectionFromParams(this.params()));
  // Structural equality so an entry-only URL change does not produce a new
  // selection reference — the reload effect must react to selection, not the
  // open entry. Delegates to `sameSelection` rather than re-listing
  // `Selection`'s fields here: a hand-rolled copy once fell out of step when
  // `term` was added, silently freezing the list on every second search
  // (#408 follow-up) — one comparator, one definition.
  readonly selection = computed(() => this.parsed().selection, {
    equal: sameSelection,
  });
  readonly entryId = computed(() => this.parsed().entryId);

  // A deep-linked entry the current list page doesn't contain, fetched by id.
  private readonly fetchedEntry = signal<EntryDto | null>(null);
  readonly openEntry = computed(() => {
    const id = this.entryId();
    if (id == null) return null;
    const inList = this.entries.entries().find((e) => e.id === id);
    if (inList) return inList; // the live list copy wins (freshest state)
    const fetched = this.fetchedEntry();
    return fetched && fetched.id === id ? fetched : null;
  });
  /** The identity of the open entry, isolated from its flags. The auto-open
   *  effect keys off this so it fires once per opened entry and never re-runs
   *  when the entry's own state changes — un-ticking it must not re-mark it. */
  private readonly openEntryId = computed(() => this.openEntry()?.id ?? null);
  /** Feed tags keyed by subscription id — feeds the tag pills on entries and the
   *  article view without threading tags through each entry DTO. */
  readonly feedTags = computed(() => {
    const m = new Map<number, SubscriptionTagDto[]>();
    for (const s of this.subs.subscriptions()) m.set(s.id, s.tags);
    return m;
  });
  readonly openEntryTags = computed(() => {
    const e = this.openEntry();
    return e ? (this.feedTags().get(e.subscriptionId) ?? []) : [];
  });
  readonly hasMore = computed(() => this.entries.nextCursor() !== null);
  /** A search request is actually in flight — not merely "some list is
   *  loading": `entries.loading()` is true for every list load, so gating the
   *  search field's spinner on that alone would show it while an unrelated
   *  feed list loads. */
  readonly searching = computed(() => this.selection().kind === 'search' && this.entries.loading());
  readonly canMarkAllRead = computed(() => markReadTarget(this.selection()) !== null);
  /** What the list header's "Last refreshed" hint shows: a feed's fetch time,
   *  or the for-you list's generation time. Null everywhere else. */
  readonly listLastRefreshed = computed(() => {
    const s = this.selection();
    if (s.kind === 'for-you') return this.recs.generatedAt();
    if (s.kind !== 'subscription') return null;
    return this.subs.subscriptions().find((x) => x.id === s.id)?.lastFetchedAt ?? null;
  });
  /** The id of the run whose picks head the for-you list — the one the header
   *  already names, so the list suppresses its boundary divider. Null off the
   *  for-you view, where there are no run dividers. */
  readonly listNewestRunId = computed(() =>
    this.selection().kind === 'for-you' ? this.recs.newestRunId() : null,
  );
  readonly paneMode = computed(() => this.layout.mode() === 'pane' && this.screen.isWide());
  readonly searchPane = computed(() => this.screen.isWide() && this.selection().kind === 'search');
  readonly splitView = computed(() => this.paneMode() || this.searchPane());
  /** An article filling the whole main area (not the split pane) — the top bar
   *  takes over its back button, reader switch and prev/next. */
  readonly articleFullscreen = computed(() => this.openEntry() !== null && !this.splitView());

  /** The user's tags for the mobile swipe row (the sidebar covers wider screens). */
  readonly headerTags = computed<TagDto[]>(() => this.subs.tagTree().map((n) => n.tag));
  readonly activeTagId = computed(() => {
    const s = this.selection();
    return s.kind === 'tag' ? (s.id ?? null) : null;
  });
  readonly allItemsActive = computed(() => this.selection().kind === 'all');

  readonly headerHeight = signal(0);
  private readonly hdr = viewChild('hdr', { read: ElementRef });
  /** Only one of the two template branches renders a list at a time. */
  private readonly list = viewChild(EntryListComponent);
  private readonly header = viewChild(ReaderHeaderComponent);
  /** Whether the mobile header's own search bar covers it — read from the
   *  child rather than owned here, since the bar's open/closed state (trigger,
   *  close button, Escape, outside click) is entirely the header's business. */
  private readonly headerSearchOpen = computed(() => this.header()?.searchOpen() ?? false);
  /** Either overlay hanging off the header — the drawer or the search bar —
   *  force-shows it. A single derived signal, read from the single place that
   *  applies the rule (the header-visibility effect in the constructor), so
   *  the two overlays can never disagree about the header's state the way two
   *  independent writers once did. */
  private readonly headerOverlayOpen = computed(
    () => this.sidebarOpen() || this.headerSearchOpen(),
  );
  // Mobile hide-on-scroll app bar — the LIST's chrome. It is not a separate
  // hide-on-scroll state: the list already keeps one (`collapsed`, same scroll
  // logic, reset on every selection change), so the bar simply mirrors it. An
  // open overlay (the drawer or the search bar) force-shows the bar, because the
  // drawer hangs below it and the search bar holds the live term. Reading the
  // list's own `collapsed` is what keeps the bar reacting to the list scroller
  // alone — never an article's or the tag row's (#128) — and what lets it follow
  // a view switch to the top of the new list without a stale-offset recompute
  // (#630). A full-screen article is a layer above the bar with its own toolbar,
  // so opening or closing one never touches it (#128).
  readonly headerHidden = computed(() =>
    this.headerOverlayOpen() ? false : (this.list()?.collapsed() ?? false),
  );
  private resizeObs?: ResizeObserver;
  /** Mobile drawer state; the sidebar is a fixed overlay below 720px. */
  readonly sidebarOpen = signal(false);
  /** Mirror of the sidebar's Organise model. Owned here so closing the drawer
   *  can reset it, and so the close-swipe pauses while a drag is possible. */
  readonly sidebarOrganising = signal(false);

  /** The tag the list is scoped to, or null for every other selection. The list
   *  header renders its glyph beside the name; the name itself comes from here
   *  too, so the heading and the glyph can never describe different tags. */
  readonly selectedTag = computed(() => {
    const s = this.selection();
    if (s.kind !== 'tag') return null;
    return this.subs.tagTree().find((n) => n.tag.id === s.id)?.tag ?? null;
  });

  /** The feed the list is scoped to, or null for every other selection. The
   *  header's edit action needs the whole subscription, not the name alone
   *  that `title` below takes from it. */
  readonly selectedSubscription = computed(() => {
    const s = this.selection();
    if (s.kind !== 'subscription') return null;
    return this.subs.subscriptions().find((x) => x.id === s.id) ?? null;
  });

  /** The selected feed, but only where the intro block belongs: the magazine
   *  layout, and only when the feed has something to introduce itself with.
   *
   *  Magazine only because the block is a member of that column — it takes the
   *  column's measure and its left edge, and reads as the card above the cards.
   *  The list layout is a dense stack with no such measure, where the same
   *  block would be a wide slab sitting on top of the rows.
   *
   *  A feed with no description, image or site URL renders no block at all
   *  rather than an empty box above the first row. The check belongs here and
   *  nowhere else: a component cannot decline to be created, so a self-guard
   *  inside FeedIntroComponent could never suppress the host element's own
   *  padding. */
  readonly feedIntroSubscription = computed(() => {
    if (this.layout.mode() !== 'magazine') return null;
    const sub = this.selectedSubscription();
    if (sub === null) return null;
    return sub.description !== null || sub.imageUrl !== null || sub.siteUrl !== null ? sub : null;
  });

  readonly title = computed(() => {
    // Read as a dependency, not used directly: TranslocoService.translate() is
    // one-shot, so the heading would keep the language it was first computed in
    // unless a language signal pulls this computed through a re-evaluation on a
    // switch. Every arm below reads a translation, so every arm needs it (#411).
    this.language.lang();
    const s = this.selection();
    if (s.kind === 'favorites') return this.i18n.translate('reader.favorites');
    if (s.kind === 'kept') return this.i18n.translate('reader.kept');
    if (s.kind === 'viewed') return this.i18n.translate('reader.viewed');
    if (s.kind === 'for-you') return this.i18n.translate('reader.forYou');
    if (s.kind === 'all') return this.i18n.translate('reader.allItems');
    if (s.kind === 'tag')
      return this.selectedTag()?.name ?? this.i18n.translate('reader.tagFallback');
    if (s.kind === 'search') return `${this.searchTitlePrefix()} ${this.searchTitleBody()}`;
    return (
      this.subs.subscriptions().find((x) => x.id === s.id)?.title ??
      this.i18n.translate('reader.feedFallback')
    );
  });

  /** How much the named list holds — the same number the sidebar row shows for
   *  it, so the row, the heading and the tab can never disagree. The unread
   *  count where the sidebar counts unread, the item count where it counts
   *  items (#709).
   *
   *  Zero means "nothing to say", which covers three cases at once: an empty
   *  list, which shows no number the way the sidebar drops the badge; a search,
   *  which already carries its own result count in the heading and the tab and
   *  must not be given a second; and a list whose count has not loaded yet. */
  readonly titleCount = computed<TitleCount>(() => {
    const s = this.selection();
    switch (s.kind) {
      case 'all':
        return unread(this.subs.totalUnread());
      case 'tag':
        return unread(this.subs.tagTree().find((n) => n.tag.id === s.id)?.unreadCount ?? 0);
      case 'subscription':
        return unread(this.subs.subscriptions().find((x) => x.id === s.id)?.unreadCount ?? 0);
      case 'favorites':
        return items(this.subs.favoritesCount());
      case 'kept':
        return items(this.subs.keptCount());
      case 'viewed':
        return items(this.subs.viewedCount());
      case 'for-you':
        return unread(this.recs.forYouCount());
      case 'saved-searches':
        return unread(0);
      case 'search':
        return items(0);
    }
  });

  /** The search title's small, muted lead ("Results for"). Split out from the
   *  body below so the entry list can render it at a smaller, muted weight
   *  while the term and count stay prominent (#581 follow-up) — `title()`
   *  above still concatenates the two into the one string the tab title and
   *  the heading's accessible name need. */
  readonly searchTitlePrefix = computed(() => {
    this.language.lang();
    return this.i18n.translate('reader.searchResultsPrefix');
  });

  /** The search heading's body — the quoted term and, unlike every other
   *  title, a result count with its own rules about when it may be shown. */
  readonly searchTitleBody = computed(() => {
    this.language.lang();
    const term = visibleSearchTerm(this.selection().term ?? '');
    // No count while the search is in flight: EntriesStore.load() clears
    // nextCursor synchronously but deliberately keeps the PREVIOUS list
    // rendered until the response lands (#254) — so for that whole window
    // `entries()` still holds the old term's rows and `hasMore()` reads as
    // false regardless of what the new term will return. Counting them would
    // flash a stale, or even a false "no matches", number. Gated on the same
    // condition the spinner uses, so the two can never disagree.
    if (this.searching()) return this.i18n.translate('reader.searchResults', { term });

    // The loaded count, not a COUNT(*) total — the list pages 50 at a time, so
    // it lies unless it is labelled: a trailing '+' when another page is still
    // out there, the exact number once there isn't.
    const count = this.entries.entries().length;
    const key = this.hasMore() ? 'reader.searchResultsCountMore' : 'reader.searchResultsCount';

    return this.i18n.translate(key, { term, count });
  });

  /** The quoted term alone (#581 follow-up round 2) — what the entry list
   *  renders as `.results-term`, now that the count moves into its own pill
   *  beside it instead of trailing the term as "— {{count}}" text. Reuses
   *  the same body-only `reader.searchResults` key `searchTitleBody` falls
   *  back to while loading, since that key IS just the quoted term. */
  readonly searchTitleTerm = computed(() => {
    this.language.lang();
    const term = visibleSearchTerm(this.selection().term ?? '');
    return this.i18n.translate('reader.searchResults', { term });
  });

  /** The pill's own text — just the number, with a trailing '+' when another
   *  page is still out there, or null to render no pill at all. Null covers
   *  two cases the pill must stay silent for: a search still in flight (see
   *  `searchTitleBody`'s #254 comment — the same trap, the same guard), and
   *  a reload that hasn't landed a first count yet. */
  readonly searchCountLabel = computed<string | null>(() => {
    if (this.searching()) return null;
    const count = this.entries.entries().length;
    return this.hasMore() ? `${count}+` : `${count}`;
  });

  private readonly viewedOnOpen = new Set<number>();

  /** Ids of entries removed from the saved view on screen. The entry list
   *  renders these with the leaving class: the row fades, then its slot
   *  collapses in place. The entry stays in the list data so the magazine plan
   *  never re-flows around the hole (#478) — a reload drops it for real. */
  readonly leavingIds = signal<ReadonlySet<number>>(new Set());

  constructor() {
    // Loads SetupService.passkeySignInAvailable, gating passkeyOfferEligible
    // above -- this route is never behind setupRedirectGuard, so nothing else
    // triggers that fetch here. Gated on isPasskeySupported() first: false
    // for nearly every test in this suite (jsdom carries no
    // PublicKeyCredential), and a browser that cannot run the ceremony at all
    // has no use for the answer regardless. catchError mirrors
    // setupRedirectGuard/requireSetupGuard's own handling of this exact
    // observable (fix round 1) -- an uncaught failure here would otherwise
    // throw on reader boot, a hot path, rather than just leaving the offer
    // unavailable the way a `false`/null availability already does.
    if (isPasskeySupported()) {
      this.setup
        .ensureLoaded()
        .pipe(catchError(() => of(false)))
        .subscribe();
    }
    // Reload the list and sidebar counts whenever the selection (not the open
    // entry) changes. A new list has no removed rows, so clear the collapsed set
    // with it — otherwise a recycled id would render an incoming row already
    // collapsed.
    effect(() => {
      const q = queryFromSelection(this.selection());
      untracked(() => {
        this.leavingIds.set(new Set());
        this.entries.load(q);
        this.subs.loadIfStale();
      });
    });
    // Dismiss the mobile drawer once a new selection is chosen from it.
    effect(() => {
      this.selection();
      untracked(() => this.sidebarOpen.set(false));
    });
    // Mark the opened entry viewed exactly once per session — even if the PATCH
    // fails and the flag rolls back, we never re-fire. Opening sends the viewed
    // flag alone; the backend reads it too (ViewedImpliesHiddenListener) and
    // localStatePatch mirrors that here, so one flag on the wire moves both.
    effect(() => {
      if (this.openEntryId() === null) return;
      untracked(() => {
        const e = this.openEntry();
        if (!e || e.isViewed || this.viewedOnOpen.has(e.id)) return;
        this.viewedOnOpen.add(e.id);
        this.applyOpenedPatch(e, { isViewed: true });
      });
    });
    // Deep link to an entry the current list page doesn't hold: fetch it by id so
    // it still opens. Tracks only entryId; the list copy takes over once loaded.
    effect(() => {
      const id = this.entryId();
      untracked(() => {
        if (id == null) {
          this.fetchedEntry.set(null); // reader closed — drop the stale fetch
          return;
        }
        if (this.entries.entries().some((e) => e.id === id)) return;
        if (this.fetchedEntry()?.id === id) return;
        // Id-guard the async writes: a slow response for a since-abandoned deep
        // link (e.g. Back/Forward between two cold entries) must not clobber the
        // entry now open.
        this.api.entry(id).subscribe({
          next: (r) => {
            if (this.entryId() === id) this.fetchedEntry.set(r.entry);
          },
          error: () => {
            if (this.entryId() === id) this.fetchedEntry.set(null);
          },
        });
      });
    });

    // Name the tab after the open article, or after the list when none is open.
    // The reader route carries no title of its own, so this is the only writer
    // while the reader is on screen.
    effect(() => {
      const entry = this.openEntry();
      if (entry !== null) {
        this.pageTitle.useText(entry.title);
        return;
      }
      this.pageTitle.useText(this.title(), this.titleCount().value);
    });

    // Nothing to read and nothing skipped: send the user to the picker. Purely
    // state-driven — no guard, no resolver — and gated on `resolved` so it never
    // fires against a list the server has not answered on yet. Use `replaceUrl`:
    // otherwise Back from /discover lands here and redirects again — a dead Back
    // button.
    effect(() => {
      if (!this.emptySubscriptionsNeedingOnboarding()) return;

      // Ask what the catalog holds before deciding. load() is a no-op once
      // resolved, and the store is shared with /discover, so the redirect path
      // still fetches the catalog exactly once. Untracked so the effect depends
      // on the catalog's resolution (onboardingAvailable, below), not on the
      // synchronous loading flag load() sets.
      untracked(() => this.catalog.load());
      if (!this.onboardingAvailable()) return;

      void this.router.navigate(['/discover'], { replaceUrl: true });
    });

    // The post-onboarding sweep, owned BY STATE rather than by being called:
    // RefreshService.run() early-returns while a refresh is already running, so a
    // call made from the picker could be silently swallowed by the shell's own
    // load. Expressing it as "feeds exist that have never been fetched" removes
    // the ordering question entirely.
    effect(() => {
      if (!this.awaitingFirstFetch() || this.sweptOnce()) return;
      this.sweptOnce.set(true);
      this.sweeping.set(true);
      this.refreshSvc.run();
    });

    // Close the sweep window once the onboarding sweep lands without error. A
    // failure keeps it open so the banner's retry stays available until a retry
    // succeeds. Gated on `sweeping` so no later, unrelated refresh reopens it —
    // and RefreshService.run()'s onDone fires on both success and failure, which
    // is why the clear is expressed as state here rather than in that callback.
    effect(() => {
      if (this.sweeping() && !this.refreshSvc.running() && this.refreshSvc.failure() === null) {
        untracked(() => this.sweeping.set(false));
      }
    });

    // The single authority that reloads the list after a refresh (#502). Two
    // intents, one place:
    //   - the onboarding sweep (sweeping()) fills progressively, so each
    //     landing slice reloads — a new user must not stare at an empty list
    //     for the whole sweep (#127);
    //   - every user-initiated refresh (mobile pull, header/sidebar Refresh,
    //     add-feed) reloads once, when the run finishes, so a scoped refresh
    //     never flickers or reorders mid-sweep.
    // A second reload used to live in each run()'s onDone callback (#61), so one
    // scoped refresh loaded the list twice. That reload now lives here alone.
    effect(() => {
      const slice = this.refreshSvc.slice();
      const running = this.refreshSvc.running();
      untracked(() => {
        if (slice === 0) return; // nothing has reported yet
        if (!this.sweeping() && running) return; // manual refresh: wait for finish
        this.subs.load();
        this.savedSearchesStore.load();
        // A refresh never touches tags, so reload them once when the run
        // finishes rather than on every onboarding slice (onDone reloaded them;
        // the old slice effect did not reload them at all).
        if (!running) this.tags.load();
        this.entries.load(queryFromSelection(this.selection()));
      });
    });

    // Reload the list when a for-you run completes while the user is already
    // on that feed. `completedStamp` starts at 0, which is the signal's
    // initial value, not a completion — the guard keeps a boot from reloading.
    effect(() => {
      if (this.recs.completedStamp() === 0) return;
      untracked(() => {
        if (this.selection().kind === 'for-you') this.entries.load({ view: 'for-you' });
      });
    });
  }

  ngOnInit(): void {
    this.savedSearchesStore.load();
    this.tags.load(); // the sidebar tag tree (order, empty tags) reads TagsStore
    if (!this.auth.user()) this.auth.loadMe().subscribe({ error: () => undefined });
    // Reopening the app resumes a for-you run left in flight by an earlier session.
    this.recs.resume();
    // Check once for a newer release; the sidebar shows the badge if there is
    // one. The backend caches the upstream lookup, so this call is cheap.
    this.versions.load();
  }

  ngAfterViewInit(): void {
    const hdrEl = this.hdr()?.nativeElement as HTMLElement | undefined;
    if (hdrEl && typeof ResizeObserver !== 'undefined') {
      // Floor the *fractional* rendered height, not `offsetHeight`. With the
      // mobile tag row present the bar's real height is fractional, and
      // `offsetHeight` rounds it — rounding up drops every element anchored at
      // `--app-bar-h` (the list header, the drawer) a sub-pixel *below* the
      // bar's true bottom edge, opening a hairline the scrolling list shows
      // through on iOS Safari (#122). Flooring lands them at or just under that
      // edge, so the bands overlap instead of gapping.
      this.resizeObs = new ResizeObserver(() =>
        this.headerHeight.set(Math.floor(hdrEl.getBoundingClientRect().height)),
      );
      this.resizeObs.observe(hdrEl);
    }
    // This height drives the content area's top padding and the mobile
    // drawer's offset, not just how far the header slides. The observer's
    // first callback covers the initial measurement; until it lands the
    // stylesheet's own 56px (the bare bar, no tag row) holds, which is why
    // the template binds `headerHeight() || null` rather than a raw 0.
  }

  ngOnDestroy(): void {
    this.resizeObs?.disconnect();
  }

  /**
   * Publish the bar's geometry to everything under the shell as inherited
   * custom properties (#87):
   *
   *   --app-bar-h      how much space a scrolling pane reserves at its top
   *   --app-bar-shift  how far a pane's own sticky furniture travels with the
   *                    bar when it retracts, so nothing is left hanging in the
   *                    gap the bar leaves behind
   *
   * Both are constants from the panes' point of view — `--app-bar-h` never
   * changes with the hidden state, which is what keeps their geometry fixed.
   * The bar itself never changes with an article either: the full-screen
   * article is a layer above it with its own toolbar, so this height cannot
   * churn when one opens or closes (#128).
   * Set imperatively rather than through a `[style.--x]` binding so there is no
   * doubt about custom-property support in the template compiler.
   */
  private readonly _publishBarVars = effect(() => {
    const style = this.hostRef.nativeElement.style;
    const h = this.headerHeight();
    if (h > 0) style.setProperty('--app-bar-h', `${h}px`);
    style.setProperty('--app-bar-shift', this.headerHidden() ? `-${h}px` : '0px');
  });

  /**
   * The mobile drawer hangs below the header, so it must never open under a
   * retracted one — that would leave a strip of backdrop where the bar should
   * be. On close the header returns to what the *list's* scroll position
   * implies, UNLESS the search bar is still open — see the constructor's
   * `headerOverlayOpen` effect, the single place that turns this (and the
   * search bar's own open/close) into a header-visibility decision. On close
   * the resting state is asked of the list itself, because the last scroller
   * to fire may have been an article's, whose offset says nothing about the
   * list (#128). Leaving the bar expanded over a scrolled-down list
   * dead-zones touch-scroll in the strip it overlays (it covers the list but
   * is not its scroller), which reads as the list refusing to scroll until
   * the swipe starts below the bar.
   */
  setSidebarOpen(open: boolean): void {
    if (!open) this.sidebarOrganising.set(false);
    this.sidebarOpen.set(open);
  }

  /** The top bar's empty middle was tapped: send the list back to the top. */
  onScrollListTop(): void {
    this.list()?.scrollToTop();
  }

  // Toggle favourite/kept and keep the sidebar badge in sync optimistically,
  // reverting the count if the PATCH fails (mirrors the unread-count handling).
  // In the matching saved view the row also leaves — patchInList owns that.
  onFavorite = (e: EntryDto): void => {
    const delta = e.isFavorite ? -1 : 1;
    this.subs.bumpFavorites(delta);
    this.patchInList(e, { isFavorite: !e.isFavorite }, () => this.subs.bumpFavorites(-delta));
  };
  onKeep = (e: EntryDto): void => {
    const delta = e.isKept ? -1 : 1;
    this.subs.bumpKept(delta);
    this.patchInList(e, { isKept: !e.isKept }, () => this.subs.bumpKept(-delta));
  };
  onToggleViewed = (e: EntryDto): void => this.setViewed(e, !e.isViewed);

  /** Reader-view outputs are payload-less; apply them to the currently open entry. */
  withOpen(fn: (e: EntryDto) => void): void {
    const e = this.openEntry();
    if (e) fn(e);
  }

  /** The tick toggles "viewed" (#482). Activating it also reads the entry (the
   *  subset invariant), so an unread entry leaves the unread list and its badge
   *  drops; deactivating only un-ticks, leaving the entry read, so the unread
   *  badge is unchanged. The Recently-read badge follows the viewed flag both
   *  ways, and the row leaves that view through patchInList. */
  private setViewed(e: EntryDto, viewed: boolean): void {
    const alsoReads = viewed && !e.isHidden;
    this.subs.bumpViewed(viewed ? 1 : -1);
    if (alsoReads) {
      this.subs.decrementUnread(e.subscriptionId);
      this.savedSearchesStore.markEntryRead(e.id);
    }
    // Let a later reopen re-mark a now-un-ticked entry.
    if (!viewed) this.viewedOnOpen.delete(e.id);
    this.patchInList(e, { isViewed: viewed }, () => {
      this.subs.bumpViewed(viewed ? -1 : 1);
      if (alsoReads) {
        this.subs.incrementUnread(e.subscriptionId);
        this.savedSearchesStore.markEntryUnread(e.id);
      }
    });
  }

  /** patchOpen plus the single saved-view rule shared by Favorites, Kept and
   *  Recently-read: when the patch removes the entry from the list on screen,
   *  fade the row out and drop it, restoring it if the PATCH fails. `onError`
   *  runs the caller's own badge revert first, then the row comes back. */
  private patchInList(e: EntryDto, patch: EntryStatePatch, onError: () => void): void {
    // leaveExcludedRow only flags the row as leaving (it stays in the list data),
    // so patchOpen still finds it whichever runs first; onError fires only async,
    // long after revertLeave is bound.
    const revertLeave = this.leaveExcludedRow(e, patch);
    this.patchOpen(e, patch, () => {
      onError();
      revertLeave();
    });
  }

  /** If `patch` drops `e` out of the saved view on screen, play the leave
   *  animation and remove the row; otherwise a no-op. Reads list membership
   *  through the same coupling the store applies, so the two never disagree. */
  private leaveExcludedRow(e: EntryDto, patch: EntryStatePatch): () => void {
    const flag = savedViewMembership(this.selection().kind);
    if (flag === null) return () => undefined;
    const after = localStatePatch(patch);
    const stillMember = (after[flag] ?? e[flag]) === true;
    if (stillMember) return () => undefined;
    return this.leaveList(e);
  }

  /** Collapse a row out of the list (entry-list `.row-slot.leaving`): the row
   *  fades, then its slot collapses in place. The entry is kept in the list data
   *  on purpose — dropping it would re-flow the magazine plan around the gap —
   *  so a reload is what finally clears it. Returns a revert that un-collapses
   *  the row if the PATCH fails. */
  private leaveList(e: EntryDto): () => void {
    this.markLeaving(e.id, true);
    return () => this.markLeaving(e.id, false);
  }

  private markLeaving(id: number, leaving: boolean): void {
    this.leavingIds.update((cur) => {
      const next = new Set(cur);
      if (leaving) next.add(id);
      else next.delete(id);
      return next;
    });
  }

  /** The on-open patch: viewed in one request, which the backend also reads
   *  (#482). Both sidebar badges are kept in sync optimistically and reverted
   *  together if the PATCH fails — the Recently-read count up for the viewed
   *  flag, and the unread count down when opening also reads a still-unread entry. */
  private applyOpenedPatch(e: EntryDto, patch: EntryStatePatch): void {
    const alsoReads = patch.isViewed === true && !e.isHidden;
    if (alsoReads) {
      this.subs.decrementUnread(e.subscriptionId);
      this.savedSearchesStore.markEntryRead(e.id);
    }
    if (patch.isViewed) this.subs.bumpViewed(1);
    this.patchOpen(e, patch, () => {
      if (alsoReads) {
        this.subs.incrementUnread(e.subscriptionId);
        this.savedSearchesStore.markEntryUnread(e.id);
      }
      if (patch.isViewed) this.subs.bumpViewed(-1);
    });
  }

  /** Following the original-article link is an active open even when the
   *  entry was opened before; the flag is one-way, so an already-viewed
   *  entry is a no-op (this fires only after an on-open PATCH rolled back). */
  onOpenOriginal = (e: EntryDto): void => {
    if (e.isViewed) return;
    this.subs.bumpViewed(1);
    this.patchOpen(e, { isViewed: true }, () => this.subs.bumpViewed(-1));
  };

  /** Apply an entry-state change. Entries in the loaded list go through the
   *  store's optimistic path; a cold-opened deep-link entry (in no list) is
   *  patched on its fetched copy and persisted directly, reverting on failure. */
  private patchOpen(e: EntryDto, patch: EntryStatePatch, onError?: () => void): void {
    if (this.entries.entries().some((x) => x.id === e.id)) {
      this.entries.setState(e.id, patch, onError);
      return;
    }
    const before = this.fetchedEntry();
    this.fetchedEntry.update((cur) => (cur && cur.id === e.id ? { ...cur, ...patch } : cur));
    this.api.updateState(e.id, patch).subscribe({
      error: () => {
        // Only revert if the same cold entry is still open — a Back/Forward to
        // another cold entry while the PATCH was in flight must not be clobbered.
        this.fetchedEntry.update((cur) => (cur && cur.id === e.id ? before : cur));
        onError?.();
      },
    });
  }

  onOpen(e: EntryDto): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { entry: entryParam(e.id, e.title) },
      queryParamsHandling: 'merge',
    });
  }
  onCloseReader(): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { entry: null },
      queryParamsHandling: 'merge',
    });
  }
  onMarkAllRead(): void {
    const target = markReadTarget(this.selection());
    if (!target) return;
    // A confirm gate, because the action is a bulk, one-click state change over
    // a whole list (or every list) that a misplaced tap used to fire silently.
    const data: ConfirmData = {
      title: this.i18n.translate('reader.markAllReadConfirm'),
      message: this.i18n.translate('reader.markAllReadConfirmMessage'),
      confirmLabel: this.i18n.translate('reader.markAllRead'),
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.markReadNow(target);
    });
  }

  private markReadNow(target: MarkReadTarget): void {
    const until = this.entries.loadedAt() || new Date().toISOString();
    if (target.scope === 'search') {
      this.api.markSearchRead(target.term, until).subscribe({
        next: () => {
          this.entries.load(queryFromSelection(this.selection()));
          this.subs.load();
          this.savedSearchesStore.load();
        },
      });
      return;
    }
    // The ranked feed has no scope to name and no watermark to move: the
    // backend marks its picks by their own entry state (#710, and #665 for why
    // a watermark here would be wrong). Its picks belong to feeds and can match
    // a saved search, so both counts beside the list are reloaded.
    if (target.scope === 'for-you') {
      this.api.markForYouRead(until).subscribe({
        next: () => {
          this.entries.load(queryFromSelection(this.selection()));
          this.subs.load();
          this.savedSearchesStore.load();
          // The badge counts unread picks (#724); the marked picks move no
          // watermark the reloads above would see, so re-read the for-you
          // summary to drop it to zero.
          this.recs.refreshStatus();
        },
      });
      return;
    }
    if (target.scope === 'saved-searches') {
      this.api.markSavedSearchesRead(until).subscribe({
        next: () => {
          this.entries.load(queryFromSelection(this.selection()));
          this.subs.load();
          this.savedSearchesStore.load();
        },
      });
      return;
    }
    this.api
      .markRead(target.scope, until, target.scope === 'all' ? undefined : target.id)
      .subscribe({
        next: () => {
          this.subs.zeroUnread(
            target.scope === 'all'
              ? 'all'
              : target.scope === 'tag'
                ? { tag: target.id }
                : { subscription: target.id },
          );
          this.entries.load(queryFromSelection(this.selection()));
          this.savedSearchesStore.load();
        },
      });
  }

  /** A term layers a search over the current list; an empty term drops only the
   *  search, so closing it returns to the list it was started from — a feed,
   *  tag, folder or view — instead of All items (#542). The list params
   *  (view/tag/subscription and the unread refinement) are left in the URL
   *  untouched: `selectionFromParams` gives a searchable `q` priority over them,
   *  so they change nothing while the search is active but are still there to
   *  return to once it clears. An open article is closed either way — the
   *  results, or the restored list, are a new thing to look at. Both go through
   *  the URL, so Back leaves a search the same way it leaves any other list. */
  onSearch(term: string): void {
    void this.router.navigate([], {
      relativeTo: this.route,
      queryParams: { q: term || null, entry: null },
      queryParamsHandling: 'merge',
    });
  }

  /** The current search decoded into the pair a saved search stores: the
   *  visible term and the whole-word flag. Null outside a search. The one
   *  place that reads the trailing-space signal off a live selection — every
   *  comparison downstream is against the decoded pair, never against a
   *  re-encoded string (#408). */
  private readonly searchedTermAndMode = computed(() => {
    const s = this.selection();
    if (s.kind !== 'search') return null;
    const raw = s.term ?? '';

    // A phrase (wrapping quotes) overrides whole-word (a trailing space) when a
    // query carries both, exactly as the server decides it (#702), so the
    // whole-word flag is read only when the query is not a phrase.
    const phrase = isPhraseTerm(raw);

    return { term: visibleSearchTerm(raw), wholeWord: !phrase && isWholeWordTerm(raw), phrase };
  });

  /** The saved search matching the current selection, or null. A search's
   *  identity is its visible term plus its mode — the whole-word and phrase
   *  flags — so all three must match. */
  readonly currentSavedSearch = computed(() => {
    const current = this.searchedTermAndMode();
    if (current === null) return null;

    return (
      this.savedSearchesStore
        .savedSearches()
        .find(
          (saved) =>
            saved.term === current.term &&
            saved.wholeWord === current.wholeWord &&
            saved.phrase === current.phrase,
        ) ?? null
    );
  });

  protected readonly savedSearchActionLabel = computed(() =>
    this.currentSavedSearch() ? 'reader.removeSavedSearch' : 'reader.saveSearch',
  );

  /** The mobile short label beside the save-search button's icon (#581
   *  follow-up) — same state, a shorter word for the narrow header. */
  protected readonly savedSearchActionShortLabel = computed(() =>
    this.currentSavedSearch() ? 'reader.removeSavedSearchShort' : 'reader.saveSearchShort',
  );

  /** Save the search being looked at, or drop it when it is already saved —
   *  one command, because the header offers one button whose label and icon
   *  flip on the same state this reads. Saving toasts a confirmation on the
   *  real HTTP success; removing is a delete and goes through a confirm
   *  dialog first (#581). */
  onToggleSavedSearch(): void {
    const saved = this.currentSavedSearch();
    if (saved) {
      this.confirmRemoveSavedSearch(saved.id);

      return;
    }

    const current = this.searchedTermAndMode();
    if (!current) return;
    this.savedSearchesStore.createSavedSearch(current.term, current.wholeWord, current.phrase, () =>
      this.toast.show({
        message: this.i18n.translate('reader.searchSaved'),
        durationMs: CONFIRMATION_DURATION_MS,
      }),
    );
  }

  private confirmRemoveSavedSearch(id: number): void {
    const data: ConfirmData = {
      title: this.i18n.translate('reader.removeSavedSearchConfirm'),
      message: this.i18n.translate('reader.removeSavedSearchConfirmMessage'),
      confirmLabel: this.i18n.translate('reader.removeSavedSearch'),
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.savedSearchesStore.removeSavedSearch(id);
    });
  }

  /** The sidebar's per-search mail icon: confirm before flipping
   *  `includeInDigest`, with different copy for turning it on versus off. */
  confirmToggleDigest(row: SavedSearchDto): void {
    const enabling = !row.includeInDigest;
    const data: ConfirmData = enabling
      ? {
          title: this.i18n.translate('reader.digest.enableConfirm'),
          message: this.i18n.translate('reader.digest.enableConfirmMessage', {
            term: row.term,
          }),
          confirmLabel: this.i18n.translate('reader.digest.enableConfirmAction'),
        }
      : {
          title: this.i18n.translate('reader.digest.disableConfirm'),
          message: this.i18n.translate('reader.digest.disableConfirmMessage', {
            term: row.term,
          }),
          confirmLabel: this.i18n.translate('reader.digest.disableConfirmAction'),
        };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.savedSearchesStore.setIncludeInDigest(row.id, enabling);
    });
  }

  /** The global refresh: sweep every due feed. The single reload authority
   *  (#502) reloads the list once the run finishes. */
  onRefresh(): void {
    this.refreshSvc.run();
  }

  /** Map the current selection to a refresh scope, or null where a scoped
   *  refresh doesn't apply (the cross-feed favorites/kept views). A subscription
   *  resolves to its underlying feed id — the API keys refresh by feed, and a
   *  subscription id is a different id space. */
  private refreshScope(s = this.selection()): RefreshScope | null {
    switch (s.kind) {
      case 'all':
        return {};
      case 'tag':
        return s.id != null ? { tagId: s.id } : null;
      case 'subscription': {
        const feedId = this.subs.subscriptions().find((x) => x.id === s.id)?.feedId;
        return feedId != null ? { feedId } : null;
      }
      default:
        return null;
    }
  }

  /** The list-scoped refresh (header button + mobile pull): sweep only the feeds
   *  behind the current selection. The single reload authority (#502) reloads the
   *  list once the run finishes — this path no longer reloads it itself. */
  onScopedRefresh(): void {
    const scope = this.refreshScope();
    if (!scope) return;
    this.refreshSvc.run(undefined, scope);
  }

  /** The header button's start path: a for-you run is long and spends provider
   *  budget, so it is confirmed every time before it begins. The run itself,
   *  its poll loop, and its stop live in `RecommendationsService`; this only
   *  guards the door.
   *
   *  A leftover failed run can be resumed at the batch that failed rather than
   *  redone from scratch, but its candidate snapshot is frozen from when it
   *  first started -- so the choice is the user's, not a silent resume (#329). */
  startRecommendations(): void {
    if (this.recs.report()?.status === 'failed') {
      this.chooseResumeOrFreshRun();
      return;
    }
    this.confirmFreshRun();
  }

  private confirmFreshRun(): void {
    const data: ConfirmData = {
      title: this.i18n.translate('reader.forYouRunConfirm'),
      message: this.i18n.translate('reader.forYouRunConfirmMessage'),
      confirmLabel: this.i18n.translate('reader.forYouRun'),
    };
    const ref = this.dialog.open<boolean>(ConfirmDialogComponent, {
      data,
      role: 'alertdialog',
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((confirmed) => {
      if (confirmed) this.recs.start();
    });
  }

  /** An unfinished (failed) run is waiting: offer to resume it or start over,
   *  rather than silently picking one. Both choices spend provider budget, so
   *  the sheet itself stands in for the plain confirm. */
  private chooseResumeOrFreshRun(): void {
    this.actionSheet
      .open({
        title: this.i18n.translate('reader.forYouUnfinishedTitle'),
        actions: [
          { id: 'resume', label: this.i18n.translate('reader.forYouResume') },
          { id: 'fresh', label: this.i18n.translate('reader.forYouStartOver') },
        ],
      })
      .subscribe((choice) => {
        if (choice === 'resume') this.recs.resumeRun();
        else if (choice === 'fresh') this.recs.start();
      });
  }

  onAddFeed(): void {
    const ref = this.dialog.open<SubscriptionDto>(AddFeedDialogComponent, {
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((sub) => {
      if (!sub) return;
      this.subs.load();
      this.savedSearchesStore.load();
      void this.router.navigate([], {
        relativeTo: this.route,
        queryParams: selectionQueryParams({ subscription: sub.id }),
        queryParamsHandling: 'merge',
      });
      // A feed discovery could read arrives with its entries already stored
      // (#290), so there is nothing left to fetch — and asking the same host
      // again a second later is precisely what a rationing site answers with
      // 429. Only a feed that came in unfetched, the scraped shortcut, still
      // needs its first fetch; scope it to that one feed so it stays fast.
      if (sub.lastFetchedAt) {
        this.entries.load(queryFromSelection(this.selection()));
        return;
      }
      // The single reload authority (#502) reloads the list once the feed's
      // first fetch finishes — this path no longer reloads it itself.
      this.refreshSvc.run(undefined, { feedId: sub.feedId });
    });
  }
}

/** A count of unread posts — what the sidebar badge counts for All items, a
 *  tag, a feed and For you, and so what the heading and the tab count there
 *  too. */
function unread(value: number): TitleCount {
  return { value, counts: 'unread' };
}

/** A count of posts, read or not — what the sidebar counts for the saved views,
 *  where "unread" is not the question the list answers. */
function items(value: number): TitleCount {
  return { value, counts: 'items' };
}

/** The entry flag a saved view filters on, or null for a list that shows every
 *  entry regardless of state (All items, a tag, a feed, For you, search). When a
 *  patch sets that flag false, the entry no longer belongs in the view and the
 *  row leaves — one rule for Favorites, Kept and Recently-read alike. */
function savedViewMembership(kind: Selection['kind']): 'isFavorite' | 'isKept' | 'isViewed' | null {
  switch (kind) {
    case 'favorites':
      return 'isFavorite';
    case 'kept':
      return 'isKept';
    case 'viewed':
      return 'isViewed';
    default:
      return null;
  }
}
