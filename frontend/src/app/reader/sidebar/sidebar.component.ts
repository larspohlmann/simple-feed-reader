// src/app/reader/sidebar/sidebar.component.ts
import { Component, inject, input, output, signal } from '@angular/core';
import { RouterLink } from '@angular/router';
import {
  CdkDrag,
  CdkDragDrop,
  CdkDropList,
  CdkDropListGroup,
  moveItemInArray,
} from '@angular/cdk/drag-drop';
import { IconComponent } from '../../shared/icon/icon.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { ViewControlsComponent } from '../view-controls/view-controls.component';
import { TagNode } from '../subscriptions.store';
import { Selection } from '../query';
import { SubscriptionDto, TagDto } from '../models';
import { RefreshService } from '../refresh.service';

/** What a sidebar drop target represents: a tag to add, or the untagged bucket. */
export type DropData = { kind: 'tag'; tag: TagDto } | { kind: 'untagged' };

@Component({
  selector: 'app-sidebar',
  imports: [
    RouterLink,
    IconComponent,
    FaviconComponent,
    ViewControlsComponent,
    CdkDropListGroup,
    CdkDropList,
    CdkDrag,
  ],
  templateUrl: './sidebar.component.html',
  styleUrl: './sidebar.component.scss',
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
  /** What is being dragged, so a tag-reorder hover shows an insertion line while
   *  a feed-onto-tag hover shows a container highlight. */
  readonly dragKind = signal<'tag' | 'feed' | null>(null);
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

  onDragStart(kind: 'tag' | 'feed'): void {
    this.dragKind.set(kind);
    if (kind === 'feed') this.dragging.set(true);
  }

  onDragEnd(): void {
    this.dragging.set(false);
    this.dragKind.set(null);
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
