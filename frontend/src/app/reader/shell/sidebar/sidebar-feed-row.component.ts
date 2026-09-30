import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CdkDragHandle } from '@angular/cdk/drag-drop';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { DismissOnOutsideDirective } from '../../../shared/dismiss-on-outside.directive';
import { Selection, selectionQueryParams } from '../../query/query';
import { SubscriptionDto } from '../../models';
import { LayoutService } from '../../layout.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { SidebarRowActions } from './sidebar-row-actions.service';

/** The inside of one feed row, tagged or untagged. The `cdkDrag` row itself
 *  stays in the sidebar; the handle here registers with it through DI. */
@Component({
  selector: 'app-sidebar-feed-row',
  imports: [
    RouterLink,
    CdkDragHandle,
    TranslocoPipe,
    IconComponent,
    FaviconComponent,
    DismissOnOutsideDirective,
  ],
  templateUrl: './sidebar-feed-row.component.html',
  styleUrl: './sidebar-feed-row.component.scss',
})
export class SidebarFeedRowComponent {
  protected readonly selectionQueryParams = selectionQueryParams;
  protected readonly manage = inject(ManageActions);
  protected readonly screen = inject(LayoutService);
  protected readonly rows = inject(SidebarRowActions);

  readonly subscription = input.required<SubscriptionDto>();
  readonly selection = input.required<Selection>();
  readonly organising = input(false);
  /** The tag the row is listed under, or null in the untagged list. */
  readonly tagId = input<number | null>(null);

  protected readonly menuKey = computed(() => {
    const tagId = this.tagId();
    const id = this.subscription().id;
    return tagId === null ? `sub-${id}` : `sub-${tagId}-${id}`;
  });

  protected readonly active = computed(() => {
    const selection = this.selection();
    return selection.kind === 'subscription' && selection.id === this.subscription().id;
  });
}
