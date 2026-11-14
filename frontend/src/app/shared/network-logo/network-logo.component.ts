import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { SocialNetworkName } from '../../core/social-network';

/** A network's brand mark (Simple Icons, CC0; Lemmy's drawn here). */
@Component({
  selector: 'app-network-logo',
  templateUrl: './network-logo.component.html',
  styleUrl: './network-logo.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NetworkLogoComponent {
  readonly network = input.required<SocialNetworkName>();
}
