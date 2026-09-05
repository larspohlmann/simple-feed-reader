import {
  ChangeDetectionStrategy,
  Component,
  OnInit,
  computed,
  inject,
  signal,
} from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { Dialog } from '@angular/cdk/dialog';
import { CdkDropListGroup } from '@angular/cdk/drag-drop';
import { TranslocoPipe } from '@jsverse/transloco';
import { Observable } from 'rxjs';
import { IconComponent } from '../../shared/icon/icon.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { ErrorBannerComponent } from '../../shared/error-banner/error-banner.component';
import { DisclosureComponent } from '../../shared/disclosure/disclosure.component';
import { DismissOnOutsideDirective } from '../../shared/dismiss-on-outside.directive';
import { SettingsStackComponent } from '../../shared/settings/stack/settings-stack.component';
import { SettingsGroupComponent } from '../../shared/settings/settings-group/settings-group.component';
import { OrganiseStore, OrganiseGroup, GroupKey } from './organise.store';
import { OrganiseTagGroupComponent } from './organise-tag-group.component';
import { OrganiseFeedRowComponent } from './organise-feed-row.component';
import { UnhealthyFeedRowComponent } from './unhealthy-feed-row.component';
import { BulkTagDialogComponent, BulkTagDialogData } from './bulk-tag-dialog.component';
import { HealthErrorDialogComponent, HealthErrorData } from './health-error-dialog.component';
import { ManageActions } from '../../reader/manage/manage-actions.service';
import { SubscriptionsStore } from '../../reader/subscriptions.store';
import { TagsStore } from '../../reader/tags.store';
import { Problem, parseProblem } from '../../core/problem';
import { isGone } from '../../reader/feed-health';
import { SubscriptionDto, SubscriptionFlags, TagDto } from '../../reader/models';

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
    CdkDropListGroup,
  ],
  providers: [OrganiseStore],
  templateUrl: './organise-section.component.html',
  styleUrl: './organise-section.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class OrganiseSectionComponent implements OnInit {
  readonly store = inject(OrganiseStore);
  protected readonly manage = inject(ManageActions);
  protected readonly subs = inject(SubscriptionsStore);
  protected readonly tags = inject(TagsStore);
  private readonly dialog = inject(Dialog);

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

  /** Four commands, not two toggles: a toggle over a mixed selection has no
   *  correct starting position (see the bulk bar's own comment in the
   *  template). */
  protected readonly visibilityMenuItems: readonly VisibilityMenuItem[] = [
    { flag: 'includeInAllItems', value: true, labelKey: 'settings.organise.showInAllItems' },
    { flag: 'includeInAllItems', value: false, labelKey: 'settings.organise.hideFromAllItems' },
    { flag: 'includeInForYou', value: true, labelKey: 'settings.organise.showInForYou' },
    { flag: 'includeInForYou', value: false, labelKey: 'settings.organise.hideFromForYou' },
  ];

  protected toggleTagFilter(key: GroupKey): void {
    this.store.tagFilter.update((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);

      return next;
    });
  }

  ngOnInit(): void {
    this.tags.load();
    this.subs.load();
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
      error: (e: HttpErrorResponse) => {
        this.store.busy.set(false);
        this.error.set(parseProblem(e));
        this.subs.load();
      },
    });
  }

  private runBulk(write$: Observable<void>): void {
    this.store.busy.set(true);
    this.error.set(null);
    write$.subscribe({
      next: () => this.store.busy.set(false),
      error: (e: HttpErrorResponse) => {
        this.store.busy.set(false);
        this.error.set(parseProblem(e));
        // Reload so the row that caused the 422 — a feed another tab already
        // deleted — disappears. The selection stays; the user decides.
        this.subs.load();
      },
    });
  }

  protected moveTag(group: OrganiseGroup, offset: number): void {
    // The template also disables the tag arrows under a filter, but
    // `store.groups()` — what canMoveTagUp/Down index — is the filtered
    // list while this swaps within the full, unfiltered tags() list; under
    // a filter that mismatch can silently swap with an invisible tag.
    if (this.store.filterActive()) return;
    const ids = this.tags.tags().map((t) => t.id);
    const from = ids.indexOf(group.tag?.id ?? -1);
    const to = from + offset;
    if (from < 0 || to < 0 || to >= ids.length) return;
    [ids[from], ids[to]] = [ids[to], ids[from]];
    this.manage.reorderTags(ids);
  }
}
