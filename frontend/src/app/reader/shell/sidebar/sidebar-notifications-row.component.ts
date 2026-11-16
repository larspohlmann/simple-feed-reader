import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { SocialNetworkName } from '../../../core/social-network';
import { CountPillComponent } from '../../../shared/count-pill/count-pill.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { selectionQueryParams } from '../../query/query';

@Component({
  selector: 'app-sidebar-notifications-row',
  imports: [RouterLink, TranslocoPipe, IconComponent, CountPillComponent],
  templateUrl: './sidebar-notifications-row.component.html',
  styleUrl: './sidebar-notifications-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.current]': 'active()' },
})
export class SidebarNotificationsRowComponent {
  protected readonly selectionQueryParams = selectionQueryParams;

  readonly network = input.required<SocialNetworkName>();
  readonly count = input(0);
  readonly active = input(false);
}
