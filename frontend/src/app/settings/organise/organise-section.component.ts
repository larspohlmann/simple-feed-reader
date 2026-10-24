import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  OnInit,
  computed,
  inject,
  signal,
  viewChild,
  viewChildren,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { HttpErrorResponse } from '@angular/common/http';
import { Dialog } from '@angular/cdk/dialog';
import { CdkDropListGroup } from '@angular/cdk/drag-drop';
import { TranslocoPipe } from '@jsverse/transloco';
import { Observable } from 'rxjs';
import { toggled } from '../../core/toggled-set';
import { IconComponent } from '../../shared/icon/icon.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { DisclosureComponent } from '../../shared/disclosure/disclosure.component';
import { DismissOnOutsideDirective } from '../../shared/dismiss-on-outside.directive';
import { IconButtonDirective } from '../../shared/icon-button/icon-button.directive';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { OrganiseStore, OrganiseGroup, GroupKey } from './organise.store';
import { OrganiseTagGroupComponent } from './organise-tag-group.component';
import { OrganiseFeedRowComponent } from './organise-feed-row.component';
import { UnhealthyFeedRowComponent } from './health/unhealthy-feed-row.component';
import { BulkTagDialogComponent, BulkTagDialogData } from './bulk-tag-dialog.component';
import {
  HealthErrorDialogComponent,
  HealthErrorData,
} from './health/health-error-dialog.component';
import { ManageActions } from '../../reader/feeds/manage/manage-actions.service';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { TagsStore } from '../../reader/state/tags.store';
import { siblingIdsByTagId, tagsInTreeOrder } from '../../reader/state/subscription-derivations';
import { SavedSearchesStore } from '../../reader/state/saved-searches.store';
import { Problem, parseProblem } from '../../core/problem';
import { isGone } from '../../reader/feeds/feed-health';
import { SubscriptionDto, TagDto } from '../../reader/models';
import { SubscriptionFlags } from '../../reader/subscription-bulk';
import { TagDragSession, measureTagLayout } from '../../reader/tag-drag-session';
import { TagDropMark } from '../../reader/tag-drop';

/** One item of the bulk bar's "Visibility" menu: which flag it sets, to what
 *  value, and the i18n key for its label. A readonly literal array so the
 *  template renders the four commands from one loop instead of four
 *  near-identical buttons. */
interface VisibilityMenuItem {
  readonly flag: keyof SubscriptionFlags;
  readonly value: boolean;
  readonly labelKey: string;
}

/**
 * The Organise page: every tag and every feed, in a tree or a flat list, with
 * multi-select and one bulk bar.
 *
 * The store is provided here, not in root: a selection must not survive leaving
 * the page. Every write goes through ManageActions — this component injects no
 * ReaderApi.
 */
@Component({
  selector: 'app-organise-section',
  imports: [
    TranslocoPipe,
    IconComponent,
    ButtonComponent,
    SkeletonComponent,
    ErrorBannerComponent,
    SettingsStackComponent,
    SettingsGroupComponent,
    OrganiseTagGroupComponent,
    OrganiseFeedRowComponent,
    UnhealthyFeedRowComponent,
    DisclosureComponent,
    DismissOnOutsideDirective,
    IconButtonDirective,
    CdkDropListGroup,
    NgTemplateOutlet,
  ],
  providers: [OrganiseStore, TagDragSession],
  templateUrl: './organise-section.component.html',
  styleUrl: './organise-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrganiseSectionComponent implements OnInit {
  readonly store = inject(OrganiseStore);
  protected readonly manage = inject(ManageActions);
  protected readonly subs = inject(SubscriptionsStore);
  protected readonly tags = inject(TagsStore);
  private readonly savedSearches = inject(SavedSearchesStore);
  private readonly dialog = inject(Dialog);
  private readonly tagSession = inject(TagDragSession);
  private readonly tree = viewChild<ElementRef<HTMLElement>>('tree');
  private readonly panelGroups = viewChildren(OrganiseTagGroupComponent);
  private readonly panelElements = viewChildren<OrganiseTagGroupComponent, ElementRef<HTMLElement>>(
    OrganiseTagGroupComponent,
    { read: ElementRef },
  );
  /** A root's panel holds its child panels, so each is looked up by key, not by its place in `store.groups()`. */
  private readonly panelByKey = computed(() => {
    const elements = this.panelElements();
    return new Map(
      this.panelGroups().map((panel, index) => [panel.group().key, elements[index].nativeElement]),
    );
  });

  /** The last refused bulk write, shown as a banner above the list. */
  readonly error = signal<Problem | null>(null);

  /** The health header's breakdown: dead feeds (the server gave up) versus
   *  merely failing ones, each shown only when non-zero. */
  protected readonly deadCount = computed(
    () => this.subs.unhealthy().filter((feed) => isGone(feed)).length,
  );
  protected readonly failingCount = computed(() => this.subs.unhealthy().length - this.deadCount());
  protected readonly tagFilterOpen = signal(false);
  protected readonly visibilityOpen = signal(false);

  /** Commands, not toggles: a toggle over a mixed selection has no
   *  correct starting position (see the bulk bar's own comment in the
   *  template). */
  protected readonly visibilityMenuItems: readonly VisibilityMenuItem[] = [
    { flag: 'includeInAllItems', value: true, labelKey: 'settings.organise.showInAllItems' },
    { flag: 'includeInAllItems', value: false, labelKey: 'settings.organise.hideFromAllItems' },
    { flag: 'includeInForYou', value: true, labelKey: 'settings.organise.showInForYou' },
    { flag: 'includeInForYou', value: false, labelKey: 'settings.organise.hideFromForYou' },
    {
      flag: 'includeInSavedSearches',
      value: true,
      labelKey: 'settings.organise.showInSavedSearches',
    },
    {
      flag: 'includeInSavedSearches',
      value: false,
      labelKey: 'settings.organise.hideFromSavedSearches',
    },
  ];

  protected toggleTagFilter(key: GroupKey): void {
    this.store.tagFilter.update((current) => toggled(current, key));
  }

  ngOnInit(): void {
    this.tags.load();
    this.subs.load();
    this.savedSearches.load();
  }

  /** Retry one unhealthy feed. `ManageActions` does the refresh, the quiet
   *  reload and the recovery toast; when the feed is still unhealthy it emits
   *  true and we open the error dialog (a settings-owned modal, so the reader
   *  layer never depends on it). */
  protected retryFeed(feed: SubscriptionDto): void {
    this.manage.retryFeed(feed).subscribe((stillFailing) => {
      if (!stillFailing) return;
      const data: HealthErrorData = { feedId: feed.feedId, title: feed.title };
      this.dialog.open<void, HealthErrorData>(HealthErrorDialogComponent, {
        data,
        panelClass: 'app-dialog',
      });
    });
  }

  protected openTagDialog(mode: 'add' | 'remove'): void {
    const data: BulkTagDialogData = {
      mode,
      subscriptions: this.store.selectedSubscriptions(),
      tags: this.tags.tags(),
    };
    const ref = this.dialog.open<TagDto | undefined>(BulkTagDialogComponent, {
      data,
      panelClass: 'app-dialog',
    });
    ref.closed.subscribe((tag) => {
      if (tag) this.applyTag(tag, mode);
    });
  }

  /** Selection survives a tag write: tagging N feeds is usually followed by a
   *  flag change on the same N. */
  applyTag(tag: TagDto, mode: 'add' | 'remove'): void {
    const ids = [...this.store.selectedIds()];
    const write$ =
      mode === 'add' ? this.manage.bulkAddTag(ids, tag) : this.manage.bulkRemoveTag(ids, tag);
    this.runBulk(write$);
  }

  setFlags(flags: SubscriptionFlags): void {
    this.runBulk(this.manage.bulkSetFlags([...this.store.selectedIds()], flags));
  }

  /** One visibility menu command was chosen: set its flag, then close the menu. */
  protected chooseVisibility(item: VisibilityMenuItem): void {
    this.setFlags({ [item.flag]: item.value });
    this.visibilityOpen.set(false);
  }

  /** Selection clears after an unsubscribe: the feeds are gone. */
  unsubscribeSelected(): void {
    const selected = this.store.selectedSubscriptions();
    this.store.busy.set(true);
    this.error.set(null);
    this.manage.bulkUnsubscribe(selected).subscribe({
      next: (removed) => {
        this.store.busy.set(false);
        if (removed) this.store.clearSelection();
      },
      error: (error: HttpErrorResponse) => {
        this.store.busy.set(false);
        this.error.set(parseProblem(error));
        this.subs.load();
      },
    });
  }

  private runBulk(write$: Observable<void>): void {
    this.store.busy.set(true);
    this.error.set(null);
    write$.subscribe({
      next: () => this.store.busy.set(false),
      error: (error: HttpErrorResponse) => {
        this.store.busy.set(false);
        this.error.set(parseProblem(error));
        // Reload so the row that caused the 422 — a feed another tab already
        // deleted — disappears. The selection stays; the user decides.
        this.subs.load();
      },
    });
  }

  protected readonly orderedTags = computed(() => tagsInTreeOrder(this.tags.tags()));
  private readonly siblingIdsByTag = computed(() => siblingIdsByTagId(this.tags.tags()));

  private readonly dropMark = computed(() => this.tagSession.drop()?.mark ?? null);

  protected readonly gapBeforeKey = computed(() => this.markedKey('before'));
  protected readonly gapAfterKey = computed(() => this.markedKey('after'));
  protected readonly nestKey = computed(() => this.markedKey('nest'));

  protected canMoveTag(group: OrganiseGroup, offset: number): boolean {
    if (group.tag === null || this.store.filterActive()) return false;
    const ids = this.siblingIdsByTag().get(group.tag.id) ?? [];
    const to = ids.indexOf(group.tag.id) + offset;
    return to >= 0 && to < ids.length;
  }

  protected moveTag(group: OrganiseGroup, offset: number): void {
    if (group.tag === null) return;
    const ids = this.siblingIds(group.tag);
    const from = ids.indexOf(group.tag.id);
    const to = from + offset;
    if (from < 0 || to < 0 || to >= ids.length) return;
    [ids[from], ids[to]] = [ids[to], ids[from]];
    this.persistTagOrder(ids);
  }

  protected startTagDrag(group: OrganiseGroup): void {
    if (group.tag === null) return;
    const tagPanels = this.panelGroups().flatMap((panel) => {
      const tag = panel.group().tag;
      return tag === null ? [] : [{ panel, tag }];
    });
    const headers = tagPanels.map(({ panel, tag }) => ({
      tagId: tag.id,
      element: panel.headElement().nativeElement,
    }));
    const lastRoot = [...tagPanels].reverse().find(({ panel }) => panel.group().depth === 0);
    const listEnd =
      lastRoot === undefined ? undefined : this.panelByKey().get(lastRoot.panel.group().key);
    this.tagSession.start(group.tag, measureTagLayout(headers, listEnd, this.treeTop()));
  }

  protected moveTagDrag(clientY: number): void {
    this.tagSession.move(clientY - this.treeTop());
  }

  protected endTagDrag(): void {
    const ended = this.tagSession.end();
    if (ended === null || this.store.filterActive()) return;
    this.manage.moveTag(ended.tag, ended.drop);
  }

  private siblingIds(tag: TagDto): number[] {
    return [...(this.siblingIdsByTag().get(tag.id) ?? [])];
  }

  private markedKey(kind: TagDropMark['kind']): GroupKey | null {
    const mark = this.dropMark();
    return mark !== null && mark.kind === kind ? mark.tagId : null;
  }

  /** Under a filter `store.groups()` is a subset of the full tag order, so no position derived from it is written. */
  private persistTagOrder(ids: number[]): void {
    if (this.store.filterActive()) return;
    this.manage.reorderTags(ids);
  }

  private treeTop(): number {
    return this.tree()?.nativeElement.getBoundingClientRect().top ?? 0;
  }
}
