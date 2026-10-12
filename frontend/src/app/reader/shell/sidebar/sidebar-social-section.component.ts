import { Component, computed, input, linkedSignal } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { SocialNetworkName } from '../../../core/social-network';
import { Selection, selectionQueryParams } from '../../query/query';
import { SocialSection } from '../../state/social-sections';
import { SidebarFeedRowComponent } from './sidebar-feed-row.component';

const collapsedKey = (network: SocialNetworkName): string => `sfr.${network}.collapsed`;

/** One network's social feeds, collapsible and remembered like Tags; a handle heads each of several accounts. */
@Component({
  selector: 'app-sidebar-social-section',
  imports: [RouterLink, TranslocoPipe, IconComponent, SidebarFeedRowComponent],
  templateUrl: './sidebar-social-section.component.html',
  styleUrl: './sidebar-social-section.component.scss',
})
export class SidebarSocialSectionComponent {
  readonly section = input.required<SocialSection>();
  readonly selection = input.required<Selection>();

  protected readonly expanded = linkedSignal({
    source: () => this.section().network,
    computation: (network) => localStorage.getItem(collapsedKey(network)) !== 'true',
  });
  protected readonly active = computed(() => {
    const selection = this.selection();
    return selection.kind === 'network' && selection.network === this.section().network;
  });
  protected readonly selectionQueryParams = selectionQueryParams;

  protected toggle(): void {
    const open = !this.expanded();
    this.expanded.set(open);
    localStorage.setItem(collapsedKey(this.section().network), String(!open));
  }
}
