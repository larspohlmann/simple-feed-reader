// src/app/reader/sidebar/sidebar.component.ts
import { Component, inject, input, output, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  CdkDrag,
  CdkDragDrop,
  CdkDragHandle,
  CdkDropList,
  CdkDropListGroup,
  moveItemInArray,
} from '@angular/cdk/drag-drop';
import { IconComponent } from '../../shared/icon/icon.component';
import { TagNode } from '../subscriptions.store';
import { Selection } from '../query';
import { SubscriptionDto, TagDto } from '../models';
import { RefreshService } from '../refresh.service';

/** What a sidebar drop target represents: a tag to add, or the untagged bucket. */
export type DropData = { kind: 'tag'; tag: TagDto } | { kind: 'untagged' };

@Component({
  selector: 'app-sidebar',
  imports: [RouterLink, IconComponent, CdkDropListGroup, CdkDropList, CdkDrag, CdkDragHandle],
  template: `
    <nav class="sidebar" aria-label="Feeds" cdkDropListGroup>
      <div class="actions">
        <button
          class="act"
          type="button"
          aria-label="Refresh"
          [disabled]="refreshSvc.running()"
          (click)="refresh.emit()"
        >
          <app-icon name="refresh" [size]="18" /><span>Refresh</span>
        </button>
        <button class="act" type="button" aria-label="Add feed" (click)="addFeed.emit()">
          <app-icon name="add" [size]="18" /><span>Add feed</span>
        </button>
      </div>
      @if (refreshSvc.running()) {
        <span class="prog"><i [style.width.%]="refreshSvc.progress() * 100"></i></span>
      }

      <a
        class="nav all"
        [class.active]="selection().kind === 'all'"
        [routerLink]="[]"
        [queryParams]="{ view: null, tag: null, subscription: null, entry: null }"
        queryParamsHandling="merge"
      >
        <app-icon name="inbox" [size]="18" /><span>All items</span>
        @if (totalUnread() > 0) {
          <span class="count">{{ totalUnread() }}</span>
        }
      </a>
      <a
        class="nav"
        [class.active]="selection().kind === 'favorites'"
        [routerLink]="[]"
        [queryParams]="{ view: 'favorites', tag: null, subscription: null, entry: null }"
        queryParamsHandling="merge"
      >
        <app-icon name="star" [size]="18" /><span>Favorites</span>
      </a>
      <a
        class="nav"
        [class.active]="selection().kind === 'kept'"
        [routerLink]="[]"
        [queryParams]="{ view: 'kept', tag: null, subscription: null, entry: null }"
        queryParamsHandling="merge"
      >
        <app-icon name="bookmark" [size]="18" /><span>Kept</span>
      </a>

      @if (tagTree().length) {
        <p class="label">Tags</p>
        <!-- Each tag contributes two TOP-LEVEL drop lists (never nested): a
             header list that reorders tags and accepts a feed to add the tag,
             and — when expanded — a feed list that reorders/receives feeds.
             CDK does not connect drop lists nested inside one another, so a
             wrapping list here would silently break every cross-list drop. -->
        <div class="tags">
          @for (node of tagTree(); track node.tag.id) {
            <div
              class="taghead"
              cdkDropList
              [cdkDropListData]="tagDrop(node.tag)"
              [cdkDropListEnterPredicate]="acceptOnTagHead"
              [cdkDropListSortingDisabled]="true"
              [class.drophover]="dropHover() === 'tag-' + node.tag.id"
              (cdkDropListDropped)="onTagHeadDrop($event)"
              (cdkDropListEntered)="dropHover.set('tag-' + node.tag.id)"
              (cdkDropListExited)="dropHover.set(null)"
            >
              <div class="tag" cdkDrag [cdkDragData]="node.tag" [cdkDragStartDelay]="dragDelay">
                <button
                  class="grip"
                  type="button"
                  cdkDragHandle
                  [attr.aria-label]="'Reorder ' + node.tag.name"
                >
                  <app-icon name="drag_indicator" [size]="18" />
                </button>
                <button
                  class="expand"
                  type="button"
                  [attr.aria-expanded]="expanded().has(node.tag.id)"
                  [attr.aria-label]="'Toggle ' + node.tag.name"
                  (click)="toggle(node.tag.id)"
                >
                  <app-icon
                    [name]="expanded().has(node.tag.id) ? 'expand_more' : 'chevron_right'"
                    [size]="18"
                  />
                </button>
                <a
                  class="nav grow"
                  [class.active]="selection().kind === 'tag' && selection().id === node.tag.id"
                  [routerLink]="[]"
                  [queryParams]="{ tag: node.tag.id, view: null, subscription: null, entry: null }"
                  queryParamsHandling="merge"
                >
                  <span
                    class="dot"
                    [style.background]="node.tag.color || 'var(--text-muted)'"
                  ></span>
                  <span>{{ node.tag.name }}</span>
                  @if (node.unreadCount > 0) {
                    <span class="count">{{ node.unreadCount }}</span>
                  }
                </a>
                <div class="rowmenu">
                  <button
                    class="dots"
                    type="button"
                    [attr.aria-label]="'Manage ' + node.tag.name"
                    (click)="toggleMenu('tag-' + node.tag.id, $event)"
                  >
                    <app-icon name="more_horiz" [size]="18" />
                  </button>
                  @if (menuFor() === 'tag-' + node.tag.id) {
                    <div class="pop" role="menu">
                      <button role="menuitem" (click)="editTag.emit(node.tag); closeMenu()">
                        Edit tag
                      </button>
                      <button role="menuitem" (click)="deleteTag.emit(node.tag); closeMenu()">
                        Delete tag
                      </button>
                    </div>
                  }
                </div>
              </div>
            </div>
            @if (expanded().has(node.tag.id)) {
              <div
                class="tagfeeds"
                cdkDropList
                [cdkDropListData]="tagDrop(node.tag)"
                [cdkDropListEnterPredicate]="isFeedDrag"
                [class.drophover]="dropHover() === 'tagfeeds-' + node.tag.id"
                (cdkDropListDropped)="onDrop($event)"
                (cdkDropListEntered)="dropHover.set('tagfeeds-' + node.tag.id)"
                (cdkDropListExited)="dropHover.set(null)"
              >
                @for (s of node.subscriptions; track s.id) {
                  <div
                    class="feedrow"
                    cdkDrag
                    [cdkDragData]="s"
                    [cdkDragStartDelay]="dragDelay"
                    (cdkDragStarted)="dragging.set(true)"
                    (cdkDragEnded)="onDragEnd()"
                  >
                    <a
                      class="nav tag-sub"
                      [class.active]="
                        selection().kind === 'subscription' && selection().id === s.id
                      "
                      [routerLink]="[]"
                      [queryParams]="{ subscription: s.id, view: null, tag: null, entry: null }"
                      queryParamsHandling="merge"
                    >
                      <span>{{ s.title }}</span>
                      @if (s.unreadCount > 0) {
                        <span class="count">{{ s.unreadCount }}</span>
                      }
                    </a>
                    <div class="rowmenu">
                      <button
                        class="dots"
                        type="button"
                        [attr.aria-label]="'Manage ' + s.title"
                        (click)="toggleMenu('sub-' + node.tag.id + '-' + s.id, $event)"
                      >
                        <app-icon name="more_horiz" [size]="18" />
                      </button>
                      @if (menuFor() === 'sub-' + node.tag.id + '-' + s.id) {
                        <div class="pop" role="menu">
                          <button role="menuitem" (click)="editFeed.emit(s); closeMenu()">
                            Edit feed
                          </button>
                          <button role="menuitem" (click)="unsubscribe.emit(s); closeMenu()">
                            Unsubscribe
                          </button>
                        </div>
                      }
                    </div>
                  </div>
                }
              </div>
            }
          }
        </div>
      }

      @if (untagged().length || dragging()) {
        <p class="label">Feeds</p>
      }
      <div
        class="feedlist"
        cdkDropList
        [cdkDropListData]="untaggedDrop"
        [cdkDropListEnterPredicate]="isFeedDrag"
        [class.drophover]="dropHover() === 'untagged'"
        (cdkDropListDropped)="onDrop($event)"
        (cdkDropListEntered)="dropHover.set('untagged')"
        (cdkDropListExited)="dropHover.set(null)"
      >
        @for (s of untagged(); track s.id) {
          <div
            class="feedrow"
            cdkDrag
            [cdkDragData]="s"
            [cdkDragStartDelay]="dragDelay"
            (cdkDragStarted)="dragging.set(true)"
            (cdkDragEnded)="onDragEnd()"
          >
            <a
              class="nav"
              [class.active]="selection().kind === 'subscription' && selection().id === s.id"
              [routerLink]="[]"
              [queryParams]="{ subscription: s.id, view: null, tag: null, entry: null }"
              queryParamsHandling="merge"
            >
              <app-icon name="rss_feed" [size]="16" /><span>{{ s.title }}</span>
              @if (s.unreadCount > 0) {
                <span class="count">{{ s.unreadCount }}</span>
              }
            </a>
            <div class="rowmenu">
              <button
                class="dots"
                type="button"
                [attr.aria-label]="'Manage ' + s.title"
                (click)="toggleMenu('sub-' + s.id, $event)"
              >
                <app-icon name="more_horiz" [size]="18" />
              </button>
              @if (menuFor() === 'sub-' + s.id) {
                <div class="pop" role="menu">
                  <button role="menuitem" (click)="editFeed.emit(s); closeMenu()">Edit feed</button>
                  <button role="menuitem" (click)="unsubscribe.emit(s); closeMenu()">
                    Unsubscribe
                  </button>
                </div>
              }
            </div>
          </div>
        }
        @if (dragging() && untagged().length === 0) {
          <p class="dropzone-hint">Drop here to remove tags</p>
        }
      </div>
    </nav>
  `,
  styles: [
    `
      .sidebar {
        padding: var(--space-3) var(--space-2);
        display: flex;
        flex-direction: column;
        gap: 2px;
        overflow: auto;
        height: 100%;
      }
      .actions {
        display: flex;
        gap: var(--space-2);
        margin-bottom: var(--space-1);
      }
      .act {
        flex: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: var(--space-1);
        padding: var(--space-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--surface-2);
        color: var(--text-primary);
        cursor: pointer;
        font-size: var(--fs-sm);
      }
      .act:hover:not(:disabled) {
        background: var(--surface-0);
      }
      .act:disabled {
        color: var(--text-muted);
        cursor: default;
      }
      .prog {
        display: block;
        height: 3px;
        border-radius: 2px;
        background: var(--border);
        overflow: hidden;
        margin: 0 var(--space-1) var(--space-1);
      }
      .prog i {
        display: block;
        height: 100%;
        background: var(--accent);
        transition: width 0.2s;
      }
      .nav {
        display: flex;
        align-items: center;
        gap: var(--space-2);
        padding: var(--space-2);
        border-radius: var(--radius);
        color: var(--text-primary);
        text-decoration: none;
      }
      .nav > span:not(.count):not(.dot) {
        flex: 1;
        min-width: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .nav:hover {
        background: var(--surface-0);
      }
      .nav.active {
        background: var(--accent-soft);
        color: var(--accent);
      }
      .nav .count {
        margin-left: auto;
        font-size: var(--fs-sm);
        color: var(--text-muted);
      }
      .nav.active .count {
        color: var(--accent);
      }
      .label {
        font-size: var(--fs-sm);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-muted);
        margin: var(--space-3) var(--space-2) var(--space-1);
      }
      .tag {
        display: flex;
        align-items: center;
      }
      .tag .expand {
        background: none;
        border: none;
        color: var(--text-secondary);
        cursor: pointer;
        padding: var(--space-2) 0 var(--space-2) var(--space-1);
      }
      .tag .grow {
        flex: 1;
      }
      .tag-sub {
        padding-left: var(--space-6);
      }
      .dot {
        width: 9px;
        height: 9px;
        border-radius: 50%;
        flex: 0 0 auto;
      }
      .feedrow {
        display: flex;
        align-items: center;
      }
      .feedrow .nav {
        flex: 1;
        min-width: 0;
      }
      .feedrow.cdk-drag {
        cursor: grab;
      }
      /* Drop targets */
      .taghead,
      .tagfeeds,
      .feedlist {
        border-radius: var(--radius);
      }
      .drophover {
        outline: 2px dashed var(--accent);
        outline-offset: -2px;
        background: var(--accent-soft);
      }
      .dropzone-hint {
        margin: 0 var(--space-1);
        padding: var(--space-3) var(--space-2);
        text-align: center;
        font-size: var(--fs-sm);
        color: var(--text-muted);
        border: 1px dashed var(--border-strong);
        border-radius: var(--radius);
      }
      /* Drag visuals — the clone is appended to <body>, but Angular emulated
         styles still match it via its retained content attribute. */
      .cdk-drag-preview {
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: 0 6px 20px rgb(0 0 0 / 35%);
      }
      .cdk-drag-preview .rowmenu {
        display: none;
      }
      .cdk-drag-placeholder {
        opacity: 0.4;
      }
      .cdk-drag-animating {
        transition: transform 0.2s cubic-bezier(0, 0, 0.2, 1);
      }
      .rowmenu {
        position: relative;
        flex: 0 0 auto;
      }
      .grip {
        display: inline-flex;
        background: none;
        border: none;
        color: var(--text-muted);
        cursor: grab;
        padding: var(--space-2) 0 var(--space-2) var(--space-1);
        opacity: 0.35;
      }
      .grip:hover,
      .grip:focus-visible {
        opacity: 1;
      }
      .dots {
        display: inline-flex;
        background: none;
        border: none;
        color: var(--text-muted);
        cursor: pointer;
        padding: var(--space-1);
        opacity: 0.55;
      }
      .dots:hover,
      .dots:focus-visible {
        opacity: 1;
      }
      .pop {
        position: absolute;
        right: 0;
        top: 28px;
        z-index: 2;
        background: var(--surface-2);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        min-width: 140px;
      }
      .pop button {
        display: block;
        width: 100%;
        text-align: left;
        padding: var(--space-2) var(--space-3);
        background: none;
        border: none;
        color: var(--text-primary);
        cursor: pointer;
      }
      .pop button:hover {
        background: var(--surface-0);
      }
    `,
  ],
})
export class SidebarComponent {
  readonly tagTree = input.required<TagNode[]>();
  readonly untagged = input.required<SubscriptionDto[]>();
  readonly totalUnread = input.required<number>();
  readonly selection = input.required<Selection>();
  readonly loading = input(false);

  readonly editTag = output<TagDto>();
  readonly deleteTag = output<TagDto>();
  readonly editFeed = output<SubscriptionDto>();
  readonly unsubscribe = output<SubscriptionDto>();
  readonly refresh = output<void>();
  readonly addFeed = output<void>();
  /** A feed was dropped onto a tag (add) or onto Feeds (clear). */
  readonly retag = output<{ sub: SubscriptionDto; tagIds: number[] }>();
  /** Tags were reordered — the full tag id list in its new order. */
  readonly reorderTags = output<number[]>();
  /** The untagged "Feeds" list was reordered. */
  readonly reorderUntagged = output<number[]>();
  /** Feeds within one tag were reordered. */
  readonly reorderTagFeeds = output<{ tagId: number; subscriptionIds: number[] }>();

  /** True when the drag is a feed row (its data is a SubscriptionDto). */
  private isFeedData(data: unknown): data is SubscriptionDto {
    return !!data && typeof data === 'object' && 'feedUrl' in data;
  }
  /** Feed lists accept only feed drags. */
  readonly isFeedDrag = (drag: CdkDrag): boolean => this.isFeedData(drag.data);
  /** A tag header accepts a tag (to reorder) and a feed (to add the tag). */
  readonly acceptOnTagHead = (): boolean => true;

  readonly refreshSvc = inject(RefreshService);
  readonly expanded = signal<Set<number>>(new Set());
  readonly menuFor = signal<string | null>(null);

  /** True while a feed row is being dragged (reveals the empty Feeds drop zone). */
  readonly dragging = signal(false);
  /** Key of the drop target currently under the pointer, for the hover outline. */
  readonly dropHover = signal<string | null>(null);
  /** Hold-to-drag on touch so a normal swipe still scrolls the sidebar. */
  readonly dragDelay = { touch: 180, mouse: 0 };
  /** Stable drop-target for the untagged bucket. */
  readonly untaggedDrop: DropData = { kind: 'untagged' };
  /** Typed drop-target for a tag (a template literal wouldn't narrow to DropData). */
  tagDrop(tag: TagDto): DropData {
    return { kind: 'tag', tag };
  }

  onDragEnd(): void {
    this.dragging.set(false);
    this.dropHover.set(null);
  }

  /** A drop on a tag's header: reorder the tags (tag drag) or add the tag to a
   *  feed (feed drag). Header lists are single-item, so a tag reorder is a
   *  transfer between two header lists rather than an in-list sort. */
  onTagHeadDrop(event: CdkDragDrop<DropData>): void {
    this.dropHover.set(null);
    const target = event.container.data;

    if (this.isFeedData(event.item.data)) {
      this.assignOrClear(event.item.data, target);
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

    if (this.isFeedData(event.item.data)) {
      this.assignOrClear(event.item.data, target);
    }
  }

  /** Add the target tag to a feed, or clear all its tags when dropped on Feeds. */
  private assignOrClear(sub: SubscriptionDto, target: DropData): void {
    const current = sub.tags.map((t) => t.id);
    let tagIds: number[];
    if (target.kind === 'tag') {
      if (current.includes(target.tag.id)) return;
      tagIds = [...current, target.tag.id];
    } else {
      if (current.length === 0) return;
      tagIds = [];
    }
    this.retag.emit({ sub, tagIds });
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
