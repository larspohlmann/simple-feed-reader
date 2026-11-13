import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { CDK_DRAG_PARENT, CdkDragHandle } from '@angular/cdk/drag-drop';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { FaviconComponent } from '../../../shared/favicon/favicon.component';
import { Selection, selectionQueryParams } from '../../query/query';
import { SubscriptionDto } from '../../models';
import {
  SURFACE_DESCRIPTOR,
  SURFACE_DESCRIPTORS,
  ownExcludedSurfaces,
} from '../../feed-exclusions';
import { feedAvatarUrl, isPrivateFeed } from '../../state/social-sections';
import { LayoutService } from '../../layout.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { SidebarRowMenuComponent } from './sidebar-row-menu.component';
import { MenuCheckboxComponent } from '../../../shared/menu-checkbox/menu-checkbox.component';
import { FeedExclusionMarkerComponent } from './feed-exclusion-marker.component';

/** The inside of one feed row: tagged, untagged or social. A tagged or untagged row's `cdkDrag` stays
 *  in the sidebar, and the handle here registers with it through DI; a social row has none, so no handle. */
@Component({
  selector: 'app-sidebar-feed-row',
  imports: [
    NgTemplateOutlet,
    RouterLink,
    CdkDragHandle,
    TranslocoPipe,
    IconComponent,
    FaviconComponent,
    SidebarRowMenuComponent,
    MenuCheckboxComponent,
    FeedExclusionMarkerComponent,
  ],
  templateUrl: './sidebar-feed-row.component.html',
  styleUrl: './sidebar-feed-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.current]': 'active()', '[class.organising]': 'organising()' },
})
export class SidebarFeedRowComponent {
  protected readonly selectionQueryParams = selectionQueryParams;
  protected readonly feedFlags = SURFACE_DESCRIPTORS;
  protected readonly manage = inject(ManageActions);
  protected readonly screen = inject(LayoutService);
  protected readonly rows = inject(SidebarRowActions);
  protected readonly draggable = inject(CDK_DRAG_PARENT, { optional: true }) !== null;

  readonly subscription = input.required<SubscriptionDto>();
  readonly selection = input.required<Selection>();
  readonly organising = input(false);
  /** The tag the row is listed under, or null in the untagged list. */
  readonly tagId = input<number | null>(null);

  /** A private feed's exclusion is its inherent state, so it carries no marker; a profile feed is ordinary RSS. */
  protected readonly isPrivate = computed(() => isPrivateFeed(this.subscription()));

  /** A private feed's exclusion from a surface it is out of by design is no news. */
  protected readonly markerSurfaces = computed(() => {
    const surfaces = ownExcludedSurfaces(this.subscription());
    return this.isPrivate()
      ? surfaces.filter((surface) => SURFACE_DESCRIPTOR[surface].privateFeedsIncluded)
      : surfaces;
  });
  protected readonly excluded = computed(() => this.markerSurfaces().length > 0);

  protected readonly avatarUrl = computed(() => feedAvatarUrl(this.subscription()));

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
