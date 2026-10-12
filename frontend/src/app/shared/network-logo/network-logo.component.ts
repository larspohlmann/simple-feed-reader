import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { SocialNetworkName } from '../../core/social-network';

/** A network's brand mark (Simple Icons, CC0), sized like a tag glyph at `xs`; the name beside it carries the meaning. */
@Component({
  selector: 'app-network-logo',
  templateUrl: './network-logo.component.html',
  styleUrl: './network-logo.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class NetworkLogoComponent {
  readonly network = input.required<SocialNetworkName>();
}
