import { Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { SocialNetworkName } from '../../../core/social-network';
import { persistedToggle } from '../../../core/preferences/persisted-toggle';
import { Selection, selectionQueryParams } from '../../query/query';
import { SocialSection } from '../../state/social-sections';
import { SidebarFeedRowComponent } from './sidebar-feed-row.component';

const collapsedKey = (network: SocialNetworkName): string => `sfr.${network}.collapsed`;
const profilesCollapsedKey = (network: SocialNetworkName): string =>
  `sfr.${network}.profiles.collapsed`;

/** One network's social feeds, collapsible and remembered like Tags; a handle heads each of several accounts. */
@Component({
  selector: 'app-sidebar-social-section',
  imports: [RouterLink, TranslocoPipe, IconComponent, TagGlyphComponent, SidebarFeedRowComponent],
  templateUrl: './sidebar-social-section.component.html',
  styleUrl: './sidebar-social-section.component.scss',
})
export class SidebarSocialSectionComponent {
  readonly section = input.required<SocialSection>();
  readonly selection = input.required<Selection>();
  readonly organising = input(false);

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

  protected toggle(): void {
    this.collapsible.toggle();
  }

  protected toggleProfiles(): void {
    this.profilesCollapsible.toggle();
  }
}
