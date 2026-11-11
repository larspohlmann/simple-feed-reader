import { Dialog } from '@angular/cdk/dialog';
import { Overlay, OverlayRef } from '@angular/cdk/overlay';
import { ComponentPortal } from '@angular/cdk/portal';
import { Injectable, Injector, OnDestroy, inject } from '@angular/core';
import { outputToObservable } from '@angular/core/rxjs-interop';
import { TranslocoService } from '@jsverse/transloco';
import { filter, merge, take, takeUntil } from 'rxjs';
import {
  AddFeedDialogComponent,
  AddFeedDialogData,
} from '../../reader/feeds/add-feed/add-feed-dialog.component';
import { LayoutService } from '../../reader/layout.service';
import { SubscriptionDto } from '../../reader/models';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { ActionSheetItem } from '../../shared/action-sheet/action-sheet.component';
import { ActionSheet } from '../../shared/action-sheet/action-sheet.service';
import { MENU_VIEWPORT_MARGIN, TOOLBAR_MENU_POSITIONS } from '../../shared/menu-keys';
import { feedGroups, feedPath } from './feed-directory-feed-groups';
import { FeedDirectoryFeed, feedsIn } from './feed-directory-rows';
import { FeedDirectoryStore } from './feed-directory.store';
import { FeedDirectoryFeedsPopoverComponent } from './feeds-popover/feed-directory-feeds-popover.component';
import { FeedDirectorySubscribeRequest } from './row/feed-directory-row.component';

@Injectable()
export class FeedDirectorySubscriber implements OnDestroy {
  private readonly dialog = inject(Dialog);
  private readonly overlay = inject(Overlay);
  private readonly sheet = inject(ActionSheet);
  private readonly layout = inject(LayoutService);
  private readonly store = inject(FeedDirectoryStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly injector = inject(Injector);
  private readonly i18n = inject(TranslocoService);
  private popover: OverlayRef | null = null;

  open(request: FeedDirectorySubscribeRequest): void {
    const feeds = feedsIn(request.row, this.store.mode());
    if (feeds.length === 1) {
      this.subscribeTo(request.row.host, feeds[0].url);
    } else if (this.layout.isCoarse()) {
      this.openSheet(request.row.host, feeds);
    } else {
      this.openPopover(request, feeds);
    }
  }

  ngOnDestroy(): void {
    this.popover?.detach();
  }

  private subscribeTo(host: string, url: string): void {
    this.dialog
      .open<SubscriptionDto>(AddFeedDialogComponent, {
        panelClass: 'app-dialog',
        data: { url } satisfies AddFeedDialogData,
      })
      .closed.subscribe((subscription) => {
        if (!subscription) return;
        this.store.markSubscribed(host, url);
        this.subscriptions.reloadQuietly();
      });
  }

  private openSheet(host: string, feeds: readonly FeedDirectoryFeed[]): void {
    const actions: ActionSheetItem[] = feedGroups(feeds).flatMap((group) => [
      { note: `${group.category} · ${group.feeds.length}` },
      ...group.feeds.map((feed) => ({
        id: feed.url,
        label: feedPath(feed.url, host),
        symbol: feed.subscribed ? 'check' : 'add',
        disabled: feed.subscribed,
      })),
    ]);
    const total = this.i18n.translate('settings.feedDirectory.feeds', { count: feeds.length });
    this.sheet.open({ title: `${host} · ${total}`, actions }).subscribe((url) => {
      if (url) this.subscribeTo(host, url);
    });
  }

  private openPopover(
    { row, anchor }: FeedDirectorySubscribeRequest,
    feeds: readonly FeedDirectoryFeed[],
  ): void {
    this.popover?.detach();
    const popover = this.overlay.create({
      positionStrategy: this.overlay
        .position()
        .flexibleConnectedTo(anchor)
        .withPositions(TOOLBAR_MENU_POSITIONS)
        .withViewportMargin(MENU_VIEWPORT_MARGIN),
      scrollStrategy: this.overlay.scrollStrategies.close(),
      hasBackdrop: true,
      backdropClass: 'cdk-overlay-transparent-backdrop',
    });
    const content = popover.attach(
      new ComponentPortal(FeedDirectoryFeedsPopoverComponent, null, this.injector),
    );
    content.setInput('host', row.host);
    content.setInput('feeds', feeds);
    this.popover = popover;

    let closedByUser = false;
    const closeByUser = (): void => {
      closedByUser = true;
      popover.detach();
    };
    const closed = popover.detachments();
    merge(
      popover.backdropClick(),
      popover.keydownEvents().pipe(filter((event) => event.key === 'Escape')),
      outputToObservable(content.instance.dismiss),
    )
      .pipe(takeUntil(closed))
      .subscribe(closeByUser);
    outputToObservable(content.instance.choose)
      .pipe(takeUntil(closed))
      .subscribe((url) => {
        closeByUser();
        this.subscribeTo(row.host, url);
      });
    closed.pipe(take(1)).subscribe(() => {
      popover.dispose();
      if (this.popover === popover) this.popover = null;
      if (closedByUser) (anchor.querySelector('button') ?? anchor).focus({ preventScroll: true });
    });
  }
}
