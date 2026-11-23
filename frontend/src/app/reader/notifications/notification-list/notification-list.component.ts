import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  TemplateRef,
  computed,
  effect,
  inject,
  input,
  untracked,
  viewChild,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { HandednessService } from '../../../core/preferences/handedness.service';
import { IconComponent } from '../../../shared/icon/icon.component';
import { ListActionDirective } from '../../../shared/list-action/list-action.directive';
import { PendingDotsComponent } from '../../../shared/pending-dots/pending-dots.component';
import { PageLayer } from '../../../shared/scroll-host/page-layer';
import type { ScrollHost } from '../../../shared/scroll-host/scroll-host';
import { ScrollHosts } from '../../../shared/scroll-host/scroll-hosts.service';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { WarningBoxComponent } from '../../../shared/warning-box/warning-box.component';
import { ListHeaderComponent } from '../../list/list-header/list-header.component';
import { ListFrameComponent } from '../../list/list-frame/list-frame.component';
import { observeSentinel } from '../../list/paging';
import { SelectionOf } from '../../query/query';
import { hasSeveralConnectedAccounts } from '../../state/social-sections';
import { SubscriptionsStore } from '../../state/subscriptions.store';
import { NotificationRowComponent } from '../notification-row/notification-row.component';
import { NotificationsStore } from '../notifications.store';

@Component({
  selector: 'app-notification-list',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    NgTemplateOutlet,
    RouterLink,
    TranslocoPipe,
    ListFrameComponent,
    IconComponent,
    PendingDotsComponent,
    ListActionDirective,
    SkeletonComponent,
    WarningBoxComponent,
    ListHeaderComponent,
    NotificationRowComponent,
  ],
  templateUrl: './notification-list.component.html',
  styleUrl: './notification-list.component.scss',
})
export class NotificationListComponent {
  readonly selection = input.required<SelectionOf<'notifications'>>();
  readonly title = input.required<string>();
  readonly headerLeading = input<TemplateRef<unknown> | null>(null);
  readonly cornerTrailing = input<TemplateRef<unknown> | null>(null);

  protected readonly store = inject(NotificationsStore);
  private readonly subscriptions = inject(SubscriptionsStore);
  private readonly scrollHosts = inject(ScrollHosts);
  private readonly pageLayer = inject(PageLayer, { optional: true });
  protected readonly leftHanded = inject(HandednessService).leftHanded;

  private readonly rows = viewChild<ElementRef<HTMLElement>>('rows');
  private readonly sentinel = viewChild<ElementRef<HTMLElement>>('sentinel');

  protected readonly network = computed(() => this.selection().network);
  protected readonly showsAccount = computed(() =>
    hasSeveralConnectedAccounts(this.subscriptions.socialSections(), this.network()),
  );

  protected readonly hintKeys = computed(() => [
    ...(this.store.scopeMissing().length > 0 ? ['reader.notifications.scopeMissing'] : []),
    ...(this.store.needsReconnect().length > 0 ? ['reader.notifications.needsReconnect'] : []),
  ]);

  private readonly scrollHost = computed((): ScrollHost | undefined => {
    const rows = this.rows()?.nativeElement;
    return rows ? this.scrollHosts.forPage(rows, this.pageLayer) : undefined;
  });

  private readonly _open = effect(() => {
    const network = this.network();
    untracked(() => {
      this.store.open(network);
      const scroller = this.scrollHost();
      if (scroller) scroller.scrollTop = 0;
    });
  });

  private readonly _wire = effect((onCleanup) => {
    const node = this.sentinel()?.nativeElement;
    const scroller = this.scrollHost();
    // Observing afresh per cursor: a sentinel still in reach after a page reports no change.
    this.store.nextCursor();
    if (node && scroller) onCleanup(observeSentinel(scroller, node, () => this.store.loadMore()));
  });
}
