// src/app/settings/organise/organise-tag-group.component.ts
import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { CdkDrag, CdkDragDrop, CdkDropList } from '@angular/cdk/drag-drop';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { OrganiseFeedRowComponent } from './organise-feed-row.component';
import { OrganiseGroup, OrganiseStore } from './organise.store';
import { ManageActions } from '../../reader/manage/manage-actions.service';
import { LayoutService } from '../../reader/layout.service';
import { SubscriptionDto } from '../../reader/models';

/**
 * One tag panel: a header row, and — when open — its feeds.
 *
 * Two sibling drop lists, never nested: CDK does not connect a list inside
 * another list, so a wrapping list here would silently break every drop. The
 * sidebar solves the same problem the same way (see sidebar.component.html).
 *
 * A cross-group drop MOVES the feed: the source tag is removed and this group's
 * tag is added. That differs from the sidebar, where a drop only ever adds —
 * on a page that shows the whole arrangement, dragging from one group to
 * another reads as "put it there".
 */
@Component({
  selector: 'app-organise-tag-group',
  imports: [
    TranslocoPipe,
    IconComponent,
    TagGlyphComponent,
    OrganiseFeedRowComponent,
    CdkDropList,
    CdkDrag,
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

  /** Drag is pointer-only. On a phone a drag inside a scrolling page fights the
   *  scroll — the sidebar needed a long-press guard and a whole Organise mode
   *  to make it work. The arrows do the same job with none of that.
   *
   *  Also off under an active filter: `group().subscriptions` is the
   *  FILTERED list, so both a same-group reorder and a cross-group move
   *  would index or resolve against a subset of the real order — the drop
   *  would either corrupt the untagged list's positions (no permutation
   *  check on that endpoint) or 422 silently on a tag's feed order. */
  protected readonly dragDisabled = computed(
    () => this.screen.isCoarse() || this.store.filterActive(),
  );

  protected readonly expanded = computed(() => this.store.isExpanded(this.group().key));
  protected readonly state = computed(() => this.store.groupState(this.group()));

  protected readonly label = computed(
    () => this.group().tag?.name ?? this.i18n.translate('settings.organise.untagged'),
  );

  protected toggle(): void {
    this.store.toggleGroup(this.group().key);
  }

  protected onGroupSelect(selected: boolean): void {
    this.store.setGroupSelected(this.group(), selected);
  }

  protected moveFeed(subscription: SubscriptionDto, offset: number): void {
    // Defense in depth: the template also disables the arrows under a
    // filter, but `group().subscriptions` is the filtered list either way —
    // reordering against it is meaningless, not merely hidden.
    if (this.store.filterActive()) return;
    const ids = this.group().subscriptions.map((s) => s.id);
    const from = ids.indexOf(subscription.id);
    const to = from + offset;
    if (from < 0 || to < 0 || to >= ids.length) return;
    [ids[from], ids[to]] = [ids[to], ids[from]];

    this.persistOrder(ids);
  }

  /** A drop inside this group reorders it; a drop from another group moves the
   *  feed between tags. The head row is itself a `cdkDrag` (so its drop list
   *  can highlight while a feed hovers it, matching the sidebar), which means
   *  a dropped item can also be the tag header itself — this component has no
   *  cross-group view to reorder tags by drag (that is the arrows' job), so a
   *  non-feed drop is ignored rather than treated as a subscription. */
  onFeedDropped(event: CdkDragDrop<OrganiseGroup>): void {
    if (!this.isFeedDrag(event.item.data)) return;
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

  /** True when the dragged item is a feed row rather than a tag header —
   *  same duck-typing the sidebar uses (`sidebar.component.ts` `isFeedData`),
   *  since an `OrganiseGroup` carries no `feedUrl`. */
  private isFeedDrag(data: unknown): data is SubscriptionDto {
    return !!data && typeof data === 'object' && 'feedUrl' in data;
  }

  private reorderTo(subscription: SubscriptionDto, from: number, to: number): void {
    if (from === to) return;
    // Same reasoning as moveFeed(): the drag handle is already disabled
    // under a filter, but a same-list drop is guarded here too.
    if (this.store.filterActive()) return;
    const ids = this.group().subscriptions.map((s) => s.id);
    ids.splice(to, 0, ...ids.splice(from, 1));

    this.persistOrder(ids);
  }

  private persistOrder(ids: number[]): void {
    const tag = this.group().tag;
    if (tag === null) {
      this.manage.reorderUntagged(ids);
      return;
    }
    this.manage.reorderTagFeeds(tag.id, ids);
  }

  /** The feed's tags after a move into this group: the source tag goes, this
   *  group's tag arrives. A drop on the untagged group removes only the
   *  SOURCE tag, keeping every other tag the feed carries — the single-tag
   *  removal this page has that the sidebar has never had (see the design
   *  spec's "Dropping on 'Untagged' removes the tag it came from"). */
  private tagIdsAfterMove(subscription: SubscriptionDto, source: OrganiseGroup): number[] {
    const target = this.group().tag;
    const kept = subscription.tags
      .map((t) => t.id)
      .filter((id) => id !== source.tag?.id && id !== target?.id);

    return target === null ? kept : [...kept, target.id];
  }
}
