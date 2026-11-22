import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { RouterLink } from '@angular/router';
import { TranslocoPipe } from '@jsverse/transloco';
import { pluralKey } from '../../../core/i18n/plural-key';
import { SocialNetworkName } from '../../../core/social-network';
import { badgeCount } from '../../../shared/badge-count';
import { IconComponent } from '../../../shared/icon/icon.component';
import { selectionQueryParams } from '../../query/query';

@Component({
  selector: 'app-sidebar-notifications-bell',
  imports: [RouterLink, TranslocoPipe, IconComponent],
  templateUrl: './sidebar-notifications-bell.component.html',
  styleUrl: './sidebar-notifications-bell.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.current]': 'active()' },
})
export class SidebarNotificationsBellComponent {
  readonly network = input.required<SocialNetworkName>();
  readonly count = input(0);
  readonly active = input(false);

  protected readonly queryParams = computed(() =>
    selectionQueryParams({ view: 'notifications', network: this.network() }),
  );
  protected readonly badgeText = computed(() => badgeCount(this.count()));
  protected readonly labelKey = computed(() =>
    this.count() === 0
      ? 'reader.notifications.bell'
      : pluralKey('reader.notifications.bellWithUnseen', this.count()),
  );
}
