// src/app/reader/sidebar/sidebar.component.ts
import { Component, computed, inject, input, model, output, signal } from '@angular/core';
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
import { ViewControlsComponent } from '../view-controls/view-controls.component';
import { DismissOnOutsideDirective } from '../../shared/dismiss-on-outside.directive';
import { TagNode } from '../subscriptions.store';
import { Selection } from '../query';
import { SubscriptionDto, TagDto } from '../models';
import { RefreshService } from '../refresh.service';
import { AuthService } from '../../core/auth.service';
import { LayoutService } from '../layout.service';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { buildVersion } from '../../../environments/version';
import { trialDaysRemaining } from '../format';

/** What a sidebar drop target represents: a tag to add, or the untagged bucket. */
export type DropData = { kind: 'tag'; tag: TagDto } | { kind: 'untagged' };

@Component({
  selector: 'app-sidebar',
  imports: [
    RouterLink,
    IconComponent,
    TagGlyphComponent,
    FaviconComponent,
    ViewControlsComponent,
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
  /** Baked in at build time, so it names the bundle actually running. */
  readonly version = buildVersion.version;

  readonly tagTree = input.required<TagNode[]>();
  readonly untagged = input.required<SubscriptionDto[]>();
  readonly totalUnread = input.required<number>();
  readonly favoritesCount = input(0);
  readonly keptCount = input(0);
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
  private readonly auth = inject(AuthService);
  readonly screen = inject(LayoutService);
  readonly organising = model(false);
  readonly expanded = signal<Set<number>>(new Set());
  readonly menuFor = signal<string | null>(null);

  /** Whole days left in the current trial, or null when the account has no
   *  active trial. Expired trials read as null here — the account is suspended
   *  by then and never reaches this view. */
  readonly trialDaysLeft = computed<number | null>(() =>
    trialDaysRemaining(this.auth.user()?.trialEndsAt ?? null),
  );

  /** The last stretch of a trial is emphasised. */
  readonly trialEndingSoon = computed(() => {
    const daysLeft = this.trialDaysLeft();
    return daysLeft !== null && daysLeft <= 3;
  });

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
          { id: 'unsubscribe', label: this.transloco.translate('reader.unsubscribe'), danger: true },
        ],
      })
      .subscribe((choice) => {
        if (choice === 'edit') this.editFeed.emit(subscription);
        if (choice === 'unsubscribe') this.unsubscribe.emit(subscription);
      });
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
