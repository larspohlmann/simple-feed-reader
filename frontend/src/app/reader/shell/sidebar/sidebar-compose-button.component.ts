import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { SocialNetworkName } from '../../../core/social-network';
import { IconComponent } from '../../../shared/icon/icon.component';

/** Starts a new post on a network from its card head; an account that needs reconnecting can only say so. */
@Component({
  selector: 'app-sidebar-compose-button',
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './sidebar-compose-button.component.html',
  styleUrl: './sidebar-compose-button.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SidebarComposeButtonComponent {
  readonly network = input.required<SocialNetworkName>();
  readonly disabled = input(false);
  readonly compose = output<SocialNetworkName>();
}
