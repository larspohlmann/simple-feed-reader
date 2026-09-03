import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { CdkDrag, CdkDragDrop, CdkDropList, moveItemInArray } from '@angular/cdk/drag-drop';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { OrganiseFeedRowComponent } from './organise-feed-row.component';
import { OrganiseGroup, OrganiseStore } from './organise.store';
import { IconButtonDirective } from '../../shared/icon-button/icon-button.directive';
import { ManageActions } from '../../reader/manage/manage-actions.service';
import { LayoutService } from '../../reader/layout.service';
import { LanguageService } from '../../core/language.service';
import { SubscriptionDto, TagDto, isSubscriptionDrag, isTagDrag } from '../../reader/models';

/** One tag panel: a header row, and -- when open -- its feeds.
 *
 *  Two sibling drop lists, never nested: CDK doesn't connect a list inside
 *  another (see sidebar.component.html for the same fix).
 *
 *  A cross-group drop MOVES the feed (removes the source tag, adds this
 *  group's), unlike the sidebar where a drop only ever adds -- on a page
 *  showing the whole arrangement, dragging between groups reads as "put it
 *  there". */
@Component({
  selector: 'app-organise-tag-group',
  imports: [
    TranslocoPipe,
    IconComponent,
    TagGlyphComponent,
    OrganiseFeedRowComponent,
    CdkDropList,
    CdkDrag,
    IconButtonDirective,
  ],
  templateUrl: './organise-tag-group.component.html',
  styleUrl: './organise-tag-group.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrganiseTagGroupComponent {
  readonly group = input.required<OrganiseGroup>();
  readonly canMoveTagUp = input(false);
  readonly canMoveTagDown = input(false);

  readonly moveTagUp = output<void>();
  readonly moveTagDown = output<void>();

  protected readonly store = inject(OrganiseStore);
  protected readonly manage = inject(ManageActions);
  protected readonly screen = inject(LayoutService);
  private readonly i18n = inject(TranslocoService);
  private readonly language = inject(LanguageService);

  /** Drag is pointer-only: on a phone it fights the page scroll (the sidebar
   *  needed a long-press guard and a whole Organise mode; the arrows do the
   *  same job without that).
   *
   *  Also off under an active filter: `group().subscriptions` is the FILTERED
   *  list, so a reorder or cross-group move would index against a subset of
   *  the real order and either corrupt positions or 422 silently. */
  protected readonly dragDisabled = computed(
    () => this.screen.isCoarse() || this.store.filterActive(),
  );

  /** The header's own drag source is additionally off for the untagged
   *  group: it is not a tag, has no position among tags.reorderTags(), and
   *  always sits last (see GroupKey's comment) — dragging it to "reorder"
   *  would have nothing to write. */
  protected readonly headerDragDisabled = computed(
    () => this.dragDisabled() || this.group().tag === null,
  );

  protected readonly expanded = computed(() => this.store.isExpanded(this.group().key));
  protected readonly state = computed(() => this.store.groupState(this.group()));

  protected readonly label = computed(() => {
    // Read as a dependency, not used directly: translate() is one-shot, so
    // this would keep its first language unless a language signal forces a
    // re-evaluation on switch (same trap as reader-shell's `title`, #411/#659).
    this.language.lang();
    return this.group().tag?.name ?? this.i18n.translate('settings.organise.untagged');
  });

  protected toggle(): void {
    this.store.toggleGroup(this.group().key);
  }

  protected onGroupSelect(selected: boolean): void {
    this.store.setGroupSelected(this.group(), selected);
  }

  protected moveFeed(subscription: SubscriptionDto, offset: number): void {
    const ids = this.group().subscriptions.map((s) => s.id);
    const from = ids.indexOf(subscription.id);
    const to = from + offset;
    if (from < 0 || to < 0 || to >= ids.length) return;
    [ids[from], ids[to]] = [ids[to], ids[from]];

    this.persistOrder(ids);
  }

  /** A drop inside this group's feed list reorders it; a drop from another
   *  group's feed list moves the feed between tags. This list only ever
   *  carries feeds, so a non-feed drop (a dragged tag header, which the
   *  cross-group `cdkDropListGroup` also lets reach here) is ignored rather
   *  than treated as a subscription. */
  onFeedDropped(event: CdkDragDrop<OrganiseGroup>): void {
    if (!isSubscriptionDrag(event.item.data)) return;
    const subscription = event.item.data;

    if (event.previousContainer === event.container) {
      this.reorderTo(subscription, event.previousIndex, event.currentIndex);
      return;
    }

    this.manage.retag(
      subscription,
      this.tagIdsAfterMove(subscription, event.previousContainer.data),
    );
  }

  /** A drop on this group's HEADER: a dragged feed still adds this tag (same
   *  write as dropping it in the body — delegated to onFeedDropped so that
   *  rule exists once), and a dragged tag header reorders the tags
   *  themselves. The header is the only list a tag header can sensibly be
   *  dropped on: a tag has no body list of its own to sort into. */
  onHeaderDropped(event: CdkDragDrop<OrganiseGroup>): void {
    if (isSubscriptionDrag(event.item.data)) {
      this.onFeedDropped(event);
      return;
    }
    if (isTagDrag(event.item.data)) {
      this.reorderDroppedTag(event.item.data);
    }
  }

  /** Persists a tag reorder from a header-to-header drag. The order comes
   *  from `store.tags()` — the full, unfiltered tag list — never
   *  `group().subscriptions`/`group()`'s own filtered view: the same reason
   *  persistOrder() below reads the unfiltered list for feeds. Mirrors
   *  OrganiseSectionComponent.moveTag(), the arrows' own equivalent. */
  private reorderDroppedTag(draggedTag: TagDto): void {
    if (this.store.filterActive()) return;
    const targetTag = this.group().tag;
    if (targetTag === null || draggedTag.id === targetTag.id) return;

    const ids = this.store.tags().map((t) => t.id);
    const from = ids.indexOf(draggedTag.id);
    const to = ids.indexOf(targetTag.id);
    if (from < 0 || to < 0) return;
    moveItemInArray(ids, from, to);
    this.manage.reorderTags(ids);
  }

  private reorderTo(subscription: SubscriptionDto, from: number, to: number): void {
    if (from === to) return;
    const ids = this.group().subscriptions.map((s) => s.id);
    moveItemInArray(ids, from, to);

    this.persistOrder(ids);
  }

  /** Persists a reordering of this group's feeds. Guarded here once, not at
   *  each caller: `group().subscriptions` is always FILTERED, so an order
   *  derived from it under a filter would corrupt positions or 422 silently.
   *  The template also disables the arrows/drag handle under a filter; this
   *  is the defense in depth. */
  private persistOrder(ids: number[]): void {
    if (this.store.filterActive()) return;
    const tag = this.group().tag;
    if (tag === null) {
      this.manage.reorderUntagged(ids);
      return;
    }
    this.manage.reorderTagFeeds(tag.id, ids);
  }

  /** The feed's tags after a move into this group: source tag goes, this
   *  group's tag arrives. A drop on untagged removes only the SOURCE tag,
   *  keeping every other tag -- the single-tag removal the sidebar never had
   *  (design spec: "Dropping on 'Untagged' removes the tag it came from"). */
  private tagIdsAfterMove(subscription: SubscriptionDto, source: OrganiseGroup): number[] {
    const target = this.group().tag;
    const kept = subscription.tags
      .map((t) => t.id)
      .filter((id) => id !== source.tag?.id && id !== target?.id);

    return target === null ? kept : [...kept, target.id];
  }
}
