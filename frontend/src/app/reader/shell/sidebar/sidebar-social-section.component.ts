import { NgTemplateOutlet } from '@angular/common';
import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { NetworkFeedKind, SocialNetworkName } from '../../../core/social-network';
import { PersistedToggle, persistedToggle } from '../../../core/preferences/persisted-toggle';
import { Selection, selectionQueryParams } from '../../query/query';
import { LayoutService } from '../../layout.service';
import { ExclusionSurface } from '../../feed-exclusions';
import {
  NETWORK_FEED_KINDS_IN_ORDER,
  NETWORK_FEED_KIND_DESCRIPTORS,
  SocialSection,
} from '../../state/social-sections';
import { ActiveDictionary } from '../../../core/i18n/active-dictionary';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { SidebarFeedRowComponent } from './sidebar-feed-row.component';
import { SidebarGroupRowComponent } from './sidebar-group-row.component';
import { SidebarRowMenuComponent } from './sidebar-row-menu.component';
import { MenuCheckboxComponent } from '../../../shared/menu-checkbox/menu-checkbox.component';
import { FeedExclusionMarkerComponent } from './feed-exclusion-marker.component';

const collapsedKey = (network: SocialNetworkName): string => `sfr.${network}.collapsed`;
const groupCollapsedKey = (network: SocialNetworkName, kind: NetworkFeedKind): string =>
  `sfr.${network}.${NETWORK_FEED_KIND_DESCRIPTORS[kind].storageName}.collapsed`;

/** One network's social feeds, collapsible and remembered like Tags; a handle heads each of several accounts. */
@Component({
  selector: 'app-sidebar-social-section',
  imports: [
    NgTemplateOutlet,
    RouterLink,
    TranslocoPipe,
    IconComponent,
    NetworkLogoComponent,
    SidebarRowMenuComponent,
    SidebarFeedRowComponent,
    SidebarGroupRowComponent,
    MenuCheckboxComponent,
    FeedExclusionMarkerComponent,
  ],
  templateUrl: './sidebar-social-section.component.html',
  styleUrl: './sidebar-social-section.component.scss',
})
export class SidebarSocialSectionComponent {
  readonly section = input.required<SocialSection>();
  readonly selection = input.required<Selection>();
  readonly organising = input(false);

  protected readonly rows = inject(SidebarRowActions);
  protected readonly screen = inject(LayoutService);
  private readonly dictionary = inject(ActiveDictionary);

  private readonly collapsible = persistedToggle(() => collapsedKey(this.section().network));
  protected readonly expanded = this.collapsible.expanded;
  protected readonly groupToggles = Object.fromEntries(
    NETWORK_FEED_KINDS_IN_ORDER.map((kind) => [kind, this.groupToggle(kind)]),
  ) as Readonly<Record<NetworkFeedKind, PersistedToggle>>;
  protected readonly feedGroups = computed(() => {
    const section = this.section();
    return NETWORK_FEED_KINDS_IN_ORDER.map((kind) => ({
      kind,
      ...NETWORK_FEED_KIND_DESCRIPTORS[kind],
      feeds: section.groups[kind],
    })).filter((group) => group.feeds.subscriptions.length > 0);
  });
  private readonly selectionOnThisNetwork = computed(() => {
    const selection = this.selection();
    return selection.kind === 'network' && selection.network === this.section().network
      ? selection
      : null;
  });
  protected readonly active = computed(() => {
    const onThisNetwork = this.selectionOnThisNetwork();
    return onThisNetwork !== null && !onThisNetwork.feedKind;
  });
  protected readonly activeFeedKind = computed(() => this.selectionOnThisNetwork()?.feedKind);
  protected readonly selectionQueryParams = selectionQueryParams;

  protected readonly networkExcludedSurfaces = computed<ExclusionSurface[]>(() =>
    this.rows.networkExcludedSurfaces(this.section().network),
  );
  protected readonly networkExcluded = computed(() => this.networkExcludedSurfaces().length > 0);

  protected readonly menuKey = computed(() => `social-${this.section().network}`);
  protected readonly surfaceChecks = computed(() => {
    this.dictionary.loaded();
    return this.rows.networkSurfaceChecks(this.section());
  });

  protected toggle(): void {
    this.collapsible.toggle();
  }

  private groupToggle(kind: NetworkFeedKind): PersistedToggle {
    return persistedToggle(() => groupCollapsedKey(this.section().network, kind), 'collapsed');
  }
}
