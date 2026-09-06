import {
  Component,
  DestroyRef,
  computed,
  effect,
  inject,
  input,
  model,
  output,
  signal,
  untracked,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { RouterLink } from '@angular/router';
import {
  CdkDrag,
  CdkDragDrop,
  CdkDragHandle,
  CdkDropList,
  CdkDropListGroup,
  moveItemInArray,
} from '@angular/cdk/drag-drop';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { SearchFieldComponent } from '../search-field/search-field.component';
import { SidebarFootComponent } from './sidebar-foot.component';
import { DismissOnOutsideDirective } from '../../shared/dismiss-on-outside.directive';
import { TagNode } from '../subscriptions.store';
import { Selection, savedSearchParams, selectionQueryParams } from '../query';
import { SavedSearchDto, SubscriptionDto, TagDto, isSubscriptionDrag } from '../models';
import { RefreshService } from '../refresh.service';
import { RecommendationsService } from '../recommendations.service';
import { AiAvailabilityService } from '../../core/ai-availability.service';
import { LayoutService } from '../layout.service';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';

/** What a sidebar drop source or target represents: a tag, or the untagged bucket. */
export type DropData = { kind: 'tag'; tag: TagDto } | { kind: 'untagged' };

const tagIdOf = (data: DropData): number | null => (data.kind === 'tag' ? data.tag.id : null);

/** localStorage keys holding whether each sidebar section is collapsed.
 *  Namespaced under `sfr.*` like the other persisted UI preferences. */
const TAGS_COLLAPSED_KEY = 'sfr.tags.collapsed';
const FEEDS_COLLAPSED_KEY = 'sfr.feeds.collapsed';

/** How many saved-search rows the sidebar shows before the "Show more" link. */
const SIDEBAR_SAVED_SEARCH_LIMIT = 6;

/** Ids of a saved-search list, ranked unread-first (count desc) then newest
 *  first (id desc). Id is the creation-order proxy — there is no date field. */
const rankedSavedSearchIds = (searches: readonly SavedSearchDto[]): number[] =>
  [...searches].sort((a, b) => b.unreadCount - a.unreadCount || b.id - a.id).map((s) => s.id);

const sameIds = (a: readonly number[], b: readonly number[]): boolean => {
  if (a.length !== b.length) return false;
  const set = new Set(b);
  return a.every((id) => set.has(id));
};

@Component({
  selector: 'app-sidebar',
  imports: [
    RouterLink,
    IconComponent,
    TagGlyphComponent,
    FaviconComponent,
    SearchFieldComponent,
    SidebarFootComponent,
    TranslocoPipe,
    CdkDropListGroup,
    CdkDropList,
    CdkDrag,
    CdkDragHandle,
    DismissOnOutsideDirective,
  ],
  templateUrl: './sidebar.component.html',
  styleUrl: './sidebar.component.scss',
})
export class SidebarComponent {
  protected readonly selectionQueryParams = selectionQueryParams;

  readonly tagTree = input.required<TagNode[]>();
  readonly untagged = input.required<SubscriptionDto[]>();
  readonly totalUnread = input.required<number>();
  readonly favoritesCount = input(0);
  readonly keptCount = input(0);
  readonly viewedCount = input(0);
  readonly savedSearches = input<SavedSearchDto[]>([]);
  /** The saved search the list is currently showing, by id, or null. The shell
   *  decides it — re-encoding the term to string-compare made a subtly
   *  different rule (a trailing tab/nbsp reads whole-word to the decoder, not
   *  to a string match). An id compares one way only. */
  readonly activeSavedSearchId = input<number | null>(null);
  /** Whether the account has mail sending enabled. The per-search digest
   *  toggle only renders when this is true — with mail off there is nowhere
   *  for the flag to send to. */
  readonly mailEnabled = input<boolean>(false);
  /** Whether the account's own digest is on. The per-search toggle needs this
   *  too: with the digest off, per-search inclusion has no digest to appear
   *  in, so the envelope button would control nothing (#636). */
  readonly digestEnabled = input<boolean>(false);

  /** The trailing envelope button shows only when mail can send AND the account
   *  digest is on. The saved-search row styles itself around its presence,
   *  restoring plain nav-row height/padding when absent (#636). */
  protected readonly showDigestToggles = computed(() => this.mailEnabled() && this.digestEnabled());
  readonly selection = input.required<Selection>();
  readonly loading = input(false);
  /** A search request is in flight — distinct from `loading` above, which is
   *  the subscriptions store's own loading flag; conflating the two would show
   *  the search spinner while an unrelated subscriptions fetch runs. */
  readonly searchLoading = input(false);

  readonly editTag = output<TagDto>();
  readonly deleteTag = output<TagDto>();
  readonly editFeed = output<SubscriptionDto>();
  readonly unsubscribe = output<SubscriptionDto>();
  /** The "Exclude/Show in All items" menu action was chosen; the shell flips
   *  `includeInAllItems` via `ManageActions`. */
  readonly toggleAllItems = output<SubscriptionDto>();
  /** The "Exclude/Show in For You" menu action was chosen; the shell flips
   *  `includeInForYou` via `ManageActions`. */
  readonly toggleForYou = output<SubscriptionDto>();
  readonly refresh = output<void>();
  readonly addFeed = output<void>();
  /** The settled search term from the field, or '' when it is cleared. */
  // Semantic "settled search term" output, not a DOM element's search event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly search = output<string>();
  /** A feed was dragged between lists: out of `fromTagId`, into `toTagId` at
   *  `position` (a null tag id is the untagged "Feeds" list; a null position
   *  appends, as when dropped on a collapsed tag header). */
  readonly moveFeed = output<{
    sub: SubscriptionDto;
    fromTagId: number | null;
    toTagId: number | null;
    position: number | null;
  }>();
  /** Tags were reordered — the full tag id list in its new order. */
  readonly reorderTags = output<number[]>();
  /** The untagged "Feeds" list was reordered. */
  readonly reorderUntagged = output<number[]>();
  /** Feeds within one tag were reordered. */
  readonly reorderTagFeeds = output<{ tagId: number; subscriptionIds: number[] }>();
  /** The mail icon on a saved-search row was clicked; the shell confirms and
   *  flips `includeInDigest`. */
  readonly toggleDigest = output<SavedSearchDto>();

  /** Feed lists accept only feed drags. */
  readonly isFeedDrag = (drag: CdkDrag): boolean => isSubscriptionDrag(drag.data);
  /** A tag header accepts a tag (to reorder) and a feed (to add the tag). */
  readonly acceptOnTagHead = (): boolean => true;

  readonly refreshSvc = inject(RefreshService);
  readonly ai = inject(AiAvailabilityService);
  readonly recs = inject(RecommendationsService);
  readonly screen = inject(LayoutService);
  readonly organising = model(false);

  /** A convertible losing its coarse pointer (docked keyboard, DevTools
   *  emulation off) must not strand Organise mode — its exit switch only
   *  renders on coarse pointers, so a stuck `true` leaves no way out. Reset
   *  instead. */
  private readonly exitOrganiseOnFinePointer = effect(() => {
    if (!this.screen.isCoarse()) untracked(() => this.organising.set(false));
  });
  readonly expanded = signal<Set<number>>(new Set());
  readonly menuFor = signal<string | null>(null);

  /** Whether the "Saved searches" group is expanded. In-memory only, default
   *  collapsed — mirrors the tags' expand behaviour (state resets on reload). */
  /** The saved-search rows with their link params resolved once per list
   *  change. `savedSearchParams` is the one `selectionQueryParams` call site
   *  that cannot use its identity cache — an unbounded `q` must not be allowed
   *  to grow it — so calling it from the template would build a fresh object
   *  per row on every change-detection pass and re-run RouterLink's href for
   *  each one. */
  protected readonly savedSearchLinks = computed(() =>
    this.savedSearches().map((saved) => ({
      ...saved,
      params: savedSearchParams(saved.term, saved.wholeWord, saved.phrase),
    })),
  );

  readonly savedSearchesExpanded = signal(false);

  /** The frozen display order (saved-search ids). Recomputed only when the
   *  section opens or the set of searches changes — never on a count change —
   *  so reading an entry does not reshuffle the list under the reader (#876). */
  private readonly frozenSavedSearchOrder = signal<number[]>([]);

  /** Re-rank on a structural change: the initial load, a create, or a delete.
   *  Keyed on the id set only, so a count-only change leaves the order frozen. */
  private readonly refreezeOnStructuralChange = effect(() => {
    const ids = this.savedSearches().map((s) => s.id);
    if (!sameIds(ids, untracked(this.frozenSavedSearchOrder))) {
      this.frozenSavedSearchOrder.set(rankedSavedSearchIds(this.savedSearches()));
    }
  });

  /** The saved searches in frozen order, each with live params and count. */
  protected readonly orderedSavedSearches = computed(() => {
    const byId = new Map(this.savedSearchLinks().map((row) => [row.id, row]));
    return this.frozenSavedSearchOrder()
      .map((id) => byId.get(id))
      .filter((row): row is NonNullable<typeof row> => row !== undefined);
  });

  /** Total unread matches across all saved searches, for the collapsed badge. */
  readonly savedSearchesUnread = computed(() =>
    this.savedSearches().reduce((sum, saved) => sum + saved.unreadCount, 0),
  );

  toggleSavedSearches(): void {
    const opening = !this.savedSearchesExpanded();
    this.savedSearchesExpanded.set(opening);
    // Opening the section is a fresh view: re-rank with the current counts.
    if (opening) this.frozenSavedSearchOrder.set(rankedSavedSearchIds(this.savedSearches()));
  }

  /** Whether the "Tags" section is expanded. Unlike the in-memory Saved-searches
   *  and per-tag toggles, this one persists across reloads (localStorage). It
   *  defaults to expanded, so an untouched sidebar looks exactly as before. */
  readonly tagsExpanded = signal(localStorage.getItem(TAGS_COLLAPSED_KEY) !== 'true');

  toggleTags(): void {
    this.tagsExpanded.update((open) => !open);
    localStorage.setItem(TAGS_COLLAPSED_KEY, String(!this.tagsExpanded()));
  }

  /** Whether the "Feeds" (untagged) section is expanded. Persisted like the
   *  Tags section; default expanded. The drop list it heads stays mounted while
   *  collapsed (see the template), so a feed drag out of a tag still lands. */
  readonly feedsExpanded = signal(localStorage.getItem(FEEDS_COLLAPSED_KEY) !== 'true');

  toggleFeeds(): void {
    this.feedsExpanded.update((open) => !open);
    localStorage.setItem(FEEDS_COLLAPSED_KEY, String(!this.feedsExpanded()));
  }

  /** True while a feed row is being dragged (reveals the empty Feeds drop zone). */
  readonly dragging = signal(false);
  /** What is being dragged, so a tag-reorder hover shows an insertion line while
   *  a feed-onto-tag hover shows a container highlight. */
  readonly dragKind = signal<'tag' | 'feed' | null>(null);
  /** Key of the drop target currently under the pointer, for the hover outline. */
  readonly dropHover = signal<string | null>(null);
  /** Hold-to-drag on touch so a normal swipe still scrolls the sidebar. Desktop
   *  keeps the long-press guard; while organising, drags start from the explicit
   *  handle so no guard is needed. */
  readonly dragDelay = computed(() => (this.organising() ? 0 : { touch: 180, mouse: 0 }));

  private readonly sheet = inject(ActionSheet);
  private readonly transloco = inject(TranslocoService);
  private readonly destroyRef = inject(DestroyRef);

  /** Coarse pointers may drag only in Organise mode; navigation is read-only. */
  readonly dragLocked = computed(() => this.screen.isCoarse() && !this.organising());

  /** ⋯ on a tag row (coarse): sheet with the tag's actions. */
  openTagSheet(tag: TagDto): void {
    this.sheet
      .open({
        title: tag.name,
        actions: [
          { id: 'edit', label: this.transloco.translate('reader.editTag') },
          { id: 'delete', label: this.transloco.translate('reader.deleteTag'), danger: true },
        ],
      })
      // A sheet can outlive the sidebar (e.g. the shell unmounts); a late
      // choice must not emit into destroyed outputs.
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'edit') this.editTag.emit(tag);
        if (choice === 'delete') this.deleteTag.emit(tag);
      });
  }

  /** ⋯ on a feed row (coarse): sheet with the subscription's actions. */
  openFeedSheet(subscription: SubscriptionDto): void {
    this.sheet
      .open({
        title: subscription.title,
        actions: [
          { id: 'edit', label: this.transloco.translate('reader.editFeed') },
          {
            id: 'toggleAllItems',
            label: this.toggleLabel(
              subscription.includeInAllItems,
              'reader.excludeFromAllItems',
              'reader.includeInAllItems',
            ),
          },
          {
            id: 'toggleForYou',
            label: this.toggleLabel(
              subscription.includeInForYou,
              'reader.excludeFromForYou',
              'reader.includeInForYou',
            ),
          },
          {
            id: 'unsubscribe',
            label: this.transloco.translate('reader.unsubscribe'),
            danger: true,
          },
        ],
      })
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((choice) => {
        if (choice === 'edit') this.editFeed.emit(subscription);
        if (choice === 'toggleAllItems') this.toggleAllItems.emit(subscription);
        if (choice === 'toggleForYou') this.toggleForYou.emit(subscription);
        if (choice === 'unsubscribe') this.unsubscribe.emit(subscription);
      });
  }

  /** Label for a feed exclusion toggle, state-dependent: when the feed is
   *  currently included it offers to exclude, and vice versa. */
  protected toggleLabel(included: boolean, excludeKey: string, includeKey: string): string {
    return this.transloco.translate(included ? excludeKey : includeKey);
  }

  /** Tooltip for the row's exclusion marker: names exactly which surface(s)
   *  the feed is hidden from. */
  exclusionTitle(subscription: SubscriptionDto): string {
    if (!subscription.includeInAllItems && !subscription.includeInForYou) {
      return this.transloco.translate('reader.excludedFromBoth');
    }
    if (!subscription.includeInAllItems) {
      return this.transloco.translate('reader.excludedFromAllItems');
    }
    return this.transloco.translate('reader.excludedFromForYou');
  }
  /** Stable drop-target for the untagged bucket. */
  readonly untaggedDrop: DropData = { kind: 'untagged' };
  /** Typed drop-target for a tag (a template literal wouldn't narrow to DropData). */
  tagDrop(tag: TagDto): DropData {
    return { kind: 'tag', tag };
  }

  onDragStart(kind: 'tag' | 'feed'): void {
    this.dragKind.set(kind);
    if (kind === 'feed') this.dragging.set(true);
  }

  onDragEnd(): void {
    this.dragging.set(false);
    this.dragKind.set(null);
    this.dropHover.set(null);
  }

  /** A drop on a tag's header: reorder the tags (tag drag) or move a feed onto
   *  the tag (feed drag). Header lists are single-item, so a tag reorder is a
   *  transfer between two header lists rather than an in-list sort. */
  onTagHeadDrop(event: CdkDragDrop<DropData>): void {
    this.dropHover.set(null);
    const target = event.container.data;

    if (isSubscriptionDrag(event.item.data)) {
      // A tag header shows no feed list, so a feed dropped on it appends.
      this.emitMove(event.item.data, event.previousContainer.data, target, null);
      return;
    }
    if (target.kind !== 'tag') return;

    const dragged = event.item.data as TagDto;
    const ids = this.tagTree().map((n) => n.tag.id);
    const from = ids.indexOf(dragged.id);
    const to = ids.indexOf(target.tag.id);
    if (from < 0 || to < 0 || from === to) return;
    moveItemInArray(ids, from, to);
    this.reorderTags.emit(ids);
  }

  /** A drop on a feed list: reorder within it (same list) or move the feed's
   *  tags (from another list). */
  onDrop(event: CdkDragDrop<DropData>): void {
    this.dropHover.set(null);
    const target = event.container.data;

    if (event.previousContainer === event.container) {
      if (event.previousIndex === event.currentIndex) return;
      if (target.kind === 'tag') {
        const ids = (
          this.tagTree().find((n) => n.tag.id === target.tag.id)?.subscriptions ?? []
        ).map((s) => s.id);
        moveItemInArray(ids, event.previousIndex, event.currentIndex);
        this.reorderTagFeeds.emit({ tagId: target.tag.id, subscriptionIds: ids });
      } else {
        const ids = this.untagged().map((s) => s.id);
        moveItemInArray(ids, event.previousIndex, event.currentIndex);
        this.reorderUntagged.emit(ids);
      }
      return;
    }

    if (isSubscriptionDrag(event.item.data)) {
      this.emitMove(event.item.data, event.previousContainer.data, target, event.currentIndex);
    }
  }

  /** Announce a feed dragged from one list to another at a dropped position.
   *  A drop back onto the same list it came from is a reorder, handled by the
   *  callers before they reach here. */
  private emitMove(
    sub: SubscriptionDto,
    source: DropData,
    target: DropData,
    position: number | null,
  ): void {
    this.moveFeed.emit({
      sub,
      fromTagId: tagIdOf(source),
      toTagId: tagIdOf(target),
      position,
    });
  }

  toggle(tagId: number): void {
    this.expanded.update((set) => {
      const next = new Set(set);
      if (next.has(tagId)) {
        next.delete(tagId);
      } else {
        next.add(tagId);
      }
      return next;
    });
  }

  toggleMenu(key: string, ev: Event): void {
    ev.preventDefault();
    ev.stopPropagation();
    this.menuFor.update((k) => (k === key ? null : key));
  }

  closeMenu(): void {
    this.menuFor.set(null);
  }
}
