import { NgTemplateOutlet } from '@angular/common';
import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { NetworkFeedKind, SocialNetworkName } from '../../../core/social-network';
import { PersistedToggle, persistedToggle } from '../../../core/preferences/persisted-toggle';
import { Selection, selectionQueryParams } from '../../query/query';
import { LayoutService } from '../../layout.service';
import { ExclusionSurface, exclusionTitle } from '../../feed-exclusions';
import { SocialSection, feedGroupOf } from '../../state/social-sections';
import { ActiveDictionary } from '../../../core/i18n/active-dictionary';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { SidebarFeedRowComponent } from './sidebar-feed-row.component';
import { SidebarGroupRowComponent } from './sidebar-group-row.component';
import { SidebarRowMenuComponent } from './sidebar-row-menu.component';

const collapsedKey = (network: SocialNetworkName): string => `sfr.${network}.collapsed`;
const groupCollapsedKey = (network: SocialNetworkName, kind: NetworkFeedKind): string =>
  `sfr.${network}.${FEED_GROUP_ROWS[kind].storageName}.collapsed`;

interface FeedGroupRow {
  readonly label: string;
  readonly toggleLabel: string;
  readonly glyph: string;
  readonly testId: string;
  readonly storageName: string;
}

const FEED_GROUP_ROWS: Readonly<Record<NetworkFeedKind, FeedGroupRow>> = {
  search: {
    label: 'reader.socialSearches',
    toggleLabel: 'reader.toggleSocialSearches',
    glyph: 'search',
    testId: 'sidebar-social-searches',
    storageName: 'searches',
  },
  profile: {
    label: 'reader.socialFeeds',
    toggleLabel: 'reader.toggleSocialFeeds',
    glyph: 'group',
    testId: 'sidebar-social-feeds',
    storageName: 'profiles',
  },
};

const FEED_GROUP_ORDER: readonly NetworkFeedKind[] = ['search', 'profile'];

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
  private readonly i18n = inject(TranslocoService);
  private readonly dictionary = inject(ActiveDictionary);

  private readonly collapsible = persistedToggle(() => collapsedKey(this.section().network));
  protected readonly expanded = this.collapsible.expanded;
  protected readonly groupToggles: Readonly<Record<NetworkFeedKind, PersistedToggle>> = {
    search: this.groupToggle('search'),
    profile: this.groupToggle('profile'),
  };
  protected readonly feedGroups = computed(() => {
    const section = this.section();
    return FEED_GROUP_ORDER.map((kind) => ({
      kind,
      ...FEED_GROUP_ROWS[kind],
      feeds: feedGroupOf(section, kind),
    })).filter((group) => group.feeds.subscriptions.length > 0);
  });
  private readonly onThisNetwork = computed(() => {
    const selection = this.selection();
    return selection.kind === 'network' && selection.network === this.section().network;
  });
  protected readonly active = computed(() => this.onThisNetwork() && !this.selection().feedKind);
  protected readonly activeFeedKind = computed(() =>
    this.onThisNetwork() ? this.selection().feedKind : undefined,
  );
  protected readonly selectionQueryParams = selectionQueryParams;

  private readonly networkExcludedSurfaces = computed<ExclusionSurface[]>(() =>
    this.rows.networkExcludedSurfaces(this.section().network),
  );
  protected readonly networkExcluded = computed(() => this.networkExcludedSurfaces().length > 0);
  protected readonly networkExclusionTitle = computed(() => {
    this.dictionary.loaded();
    return exclusionTitle(this.i18n, this.networkExcludedSurfaces());
  });

  protected readonly menuKey = computed(() => `social-${this.section().network}`);

  protected toggle(): void {
    this.collapsible.toggle();
  }

  private groupToggle(kind: NetworkFeedKind): PersistedToggle {
    return persistedToggle(() => groupCollapsedKey(this.section().network, kind), 'collapsed');
  }
}
