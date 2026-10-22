import { Component, computed, inject, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { SocialNetworkName } from '../../../core/social-network';
import { persistedToggle } from '../../../core/preferences/persisted-toggle';
import { Selection, selectionQueryParams } from '../../query/query';
import { LayoutService } from '../../layout.service';
import { SavedSearchesStore } from '../../state/saved-searches.store';
import { SocialSection } from '../../state/social-sections';
import { SidebarRowActions } from './sidebar-row-actions.service';
import { SidebarFeedRowComponent } from './sidebar-feed-row.component';
import { SidebarGroupRowComponent } from './sidebar-group-row.component';
import { SidebarRowMenuComponent } from './sidebar-row-menu.component';

const collapsedKey = (network: SocialNetworkName): string => `sfr.${network}.collapsed`;
const profilesCollapsedKey = (network: SocialNetworkName): string =>
  `sfr.${network}.profiles.collapsed`;

/** One network's social feeds, collapsible and remembered like Tags; a handle heads each of several accounts. */
@Component({
  selector: 'app-sidebar-social-section',
  imports: [
    RouterLink,
    TranslocoPipe,
    IconComponent,
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
  private readonly savedSearches = inject(SavedSearchesStore);

  private readonly collapsible = persistedToggle(() => collapsedKey(this.section().network));
  protected readonly expanded = this.collapsible.expanded;
  private readonly profilesCollapsible = persistedToggle(
    () => profilesCollapsedKey(this.section().network),
    'collapsed',
  );
  protected readonly profilesExpanded = this.profilesCollapsible.expanded;
  private readonly onThisNetwork = computed(() => {
    const selection = this.selection();
    return selection.kind === 'network' && selection.network === this.section().network;
  });
  protected readonly active = computed(() => this.onThisNetwork() && !this.selection().feedKind);
  protected readonly profilesActive = computed(
    () => this.onThisNetwork() && this.selection().feedKind === 'profile',
  );
  protected readonly selectionQueryParams = selectionQueryParams;

  protected readonly menuKey = computed(() => `social-${this.section().network}`);

  protected readonly networkIncluded = computed(() =>
    this.savedSearches.includedNetworks().has(this.section().network),
  );

  protected toggleNetworkInSavedSearches(): void {
    const network = this.section().network;
    if (this.networkIncluded()) this.savedSearches.excludeNetwork(network);
    else this.savedSearches.includeNetwork(network);
    this.rows.closeMenu();
  }

  protected toggle(): void {
    this.collapsible.toggle();
  }

  protected toggleProfiles(): void {
    this.profilesCollapsible.toggle();
  }
}
