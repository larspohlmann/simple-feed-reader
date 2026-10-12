import { Component, computed, input, linkedSignal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { SubscriptionDto } from '../../models';
import { SocialNetworkName } from '../../social-feed';
import { Selection } from '../../query/query';
import { SocialSection } from '../../state/social-sections';
import { SidebarFeedRowComponent } from './sidebar-feed-row.component';

const collapsedKey = (network: SocialNetworkName): string => `sfr.${network}.collapsed`;

/** One network's private feeds, collapsible and remembered like Tags; a handle heads each of several accounts. */
@Component({
  selector: 'app-sidebar-social-section',
  imports: [TranslocoPipe, IconComponent, SidebarFeedRowComponent],
  templateUrl: './sidebar-social-section.component.html',
  styleUrl: './sidebar-social-section.component.scss',
})
export class SidebarSocialSectionComponent {
  readonly section = input.required<SocialSection>();
  readonly selection = input.required<Selection>();

  protected readonly expanded = linkedSignal(
    () => localStorage.getItem(collapsedKey(this.section().network)) !== 'true',
  );
  protected readonly showsHandles = computed(() => this.section().accounts.length > 1);

  protected toggle(): void {
    const open = !this.expanded();
    this.expanded.set(open);
    localStorage.setItem(collapsedKey(this.section().network), String(!open));
  }

  protected isUnrenamedHome(subscription: SubscriptionDto): boolean {
    return subscription.social?.kind === 'home' && subscription.customTitle === null;
  }
}
