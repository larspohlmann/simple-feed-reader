import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { SOCIAL_NETWORK_NAMES, SocialNetworkName } from '../../../core/social-network';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { ComposerLane } from './composer-lane';

export type PillLane = Pick<
  ComposerLane,
  'network' | 'active' | 'sent' | 'loadError' | 'sendError'
>;

/** A pill per composer lane that switches its network on or off; a sent lane's pill is locked. */
@Component({
  selector: 'app-network-pills',
  imports: [NetworkLogoComponent, TranslocoPipe],
  templateUrl: './network-pills.component.html',
  styleUrl: './network-pills.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NetworkPillsComponent {
  readonly lanes = input.required<readonly PillLane[]>();
  readonly disabled = input(false);
  readonly toggleNetwork = output<SocialNetworkName>();

  protected readonly names = SOCIAL_NETWORK_NAMES;
}
