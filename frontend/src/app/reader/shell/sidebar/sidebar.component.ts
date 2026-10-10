import {
  Component,
  ElementRef,
  afterRenderEffect,
  computed,
  effect,
  inject,
  input,
  model,
  output,
  signal,
  untracked,
  viewChild,
} from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  CdkDrag,
  CdkDragDrop,
  CdkDragHandle,
  CdkDropList,
  CdkDropListGroup,
  moveItemInArray,
} from '@angular/cdk/drag-drop';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { SearchFieldComponent } from '../search-field/search-field.component';
import { SidebarFootComponent } from './sidebar-foot.component';
import { SidebarFeedRowComponent } from './sidebar-feed-row.component';
import { SidebarSavedSearchesComponent } from './sidebar-saved-searches.component';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { DismissOnOutsideDirective } from '../../../shared/dismiss-on-outside.directive';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { TagNode } from '../../state/subscriptions.store';
import { Selection, selectionQueryParams } from '../../query/query';
import { MoveFeedToTag, SavedSearchDto, SubscriptionDto, TagDto } from '../../models';
import { isSubscriptionDrag } from '../../drag-payload';
import { RefreshService } from '../../state/refresh.service';
import { RecommendationsService } from '../../state/recommendations.service';
import { AiAvailabilityService } from '../../../core/ai-availability.service';
import { LayoutService } from '../../layout.service';
import { SidebarVisibilityService } from '../sidebar-visibility.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { TOUCH_DRAG_START_DELAY } from '../../../shared/touch-drag-delay';

/** What a sidebar drop source or target represents: a tag, or the untagged bucket. */
export type DropData = { kind: 'tag'; tag: TagDto } | { kind: 'untagged' };

const tagIdOf = (data: DropData): number | null => (data.kind === 'tag' ? data.tag.id : null);

/** A feed dragged from one list to another at a dropped position. A drop back
 *  onto the list it came from is a reorder, handled before this. */
const feedMove = (source: DropData, target: DropData, position: number | null): MoveFeedToTag => ({
  fromTagId: tagIdOf(source),
  toTagId: tagIdOf(target),
  position,
});

/** localStorage keys holding whether each sidebar section is collapsed.
 *  Namespaced under `sfr.*` like the other persisted UI preferences. */
const TAGS_COLLAPSED_KEY = 'sfr.tags.collapsed';
const FEEDS_COLLAPSED_KEY = 'sfr.feeds.collapsed';

@Component({
  selector: 'app-sidebar',
  imports: [
    RouterLink,
    IconComponent,
    TagGlyphComponent,
    SearchFieldComponent,
    SidebarFootComponent,
    SidebarFeedRowComponent,
    SidebarSavedSearchesComponent,
    TranslocoPipe,
    CdkDropListGroup,
    CdkDropList,
    CdkDrag,
    CdkDragHandle,
    DismissOnOutsideDirective,
    IconButtonDirective,
  ],
  templateUrl: './sidebar.component.html',
  styleUrl: './sidebar.component.scss',
  providers: [SidebarRowActions],
})
export class SidebarComponent {
  protected readonly selectionQueryParams = selectionQueryParams;
  protected readonly manage = inject(ManageActions);
  protected readonly rows = inject(SidebarRowActions);

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

  readonly refresh = output<void>();
  readonly addFeed = output<void>();
  /** The settled search term from the field, or '' when it is cleared. */
  // Semantic "settled search term" output, not a DOM element's search event.
  // eslint-disable-next-line @angular-eslint/no-output-native
  readonly search = output<string>();
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
  readonly visibility = inject(SidebarVisibilityService);
  readonly organising = model(false);

  private readonly collapseButton = viewChild<ElementRef<HTMLButtonElement>>('collapseButton');
  /** Skips the mount run so a page load does not pull focus onto the collapse
   *  button before the user has asked for anything. */
  private returningFromHidden = false;

  /** When the sidebar comes back from hidden, focus its collapse button so a
   *  keyboard user who clicked "Show sidebar" is not dropped to `<body>`. Its
   *  counterpart, the shell's "Show sidebar" button, focuses itself the same
   *  way when the sidebar hides. `afterRenderEffect` because the button must be
   *  in the DOM already — a plain effect fires while the column is still hidden. */
  private readonly focusOnReturn = afterRenderEffect(() => {
    const hidden = this.visibility.hidden();
    if (hidden) {
      this.returningFromHidden = true;
      return;
    }
    if (!this.returningFromHidden) return;
    this.returningFromHidden = false;
    this.collapseButton()?.nativeElement.focus();
  });

  /** A convertible losing its coarse pointer (docked keyboard, DevTools
   *  emulation off) must not strand Organise mode — its exit switch only
   *  renders on coarse pointers, so a stuck `true` leaves no way out. Reset
   *  instead. */
  private readonly exitOrganiseOnFinePointer = effect(() => {
    if (!this.screen.isCoarse()) untracked(() => this.organising.set(false));
  });
  readonly expanded = signal<Set<number>>(new Set());

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
  readonly dragDelay = computed(() => (this.organising() ? 0 : TOUCH_DRAG_START_DELAY));

  /** Coarse pointers may drag only in Organise mode; navigation is read-only. */
  readonly dragLocked = computed(() => this.screen.isCoarse() && !this.organising());

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
      this.manage.moveFeedToTag(
        event.item.data,
        feedMove(event.previousContainer.data, target, null),
      );
      return;
    }
    if (target.kind !== 'tag') return;

    const dragged = event.item.data as TagDto;
    const ids = this.tagTree().map((node) => node.tag.id);
    const from = ids.indexOf(dragged.id);
    const to = ids.indexOf(target.tag.id);
    if (from < 0 || to < 0 || from === to) return;
    moveItemInArray(ids, from, to);
    this.manage.reorderTags(ids);
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
          this.tagTree().find((node) => node.tag.id === target.tag.id)?.subscriptions ?? []
        ).map((subscription) => subscription.id);
        moveItemInArray(ids, event.previousIndex, event.currentIndex);
        this.manage.reorderTagFeeds(target.tag.id, ids);
      } else {
        const ids = this.untagged().map((subscription) => subscription.id);
        moveItemInArray(ids, event.previousIndex, event.currentIndex);
        this.manage.reorderUntagged(ids);
      }
      return;
    }

    if (isSubscriptionDrag(event.item.data)) {
      this.manage.moveFeedToTag(
        event.item.data,
        feedMove(event.previousContainer.data, target, event.currentIndex),
      );
    }
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
}
