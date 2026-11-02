import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { SocialNetworkName } from '../../../core/social-network';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { PostActions } from '../composer/post-actions.service';
import { PostNetworks } from '../share/post-networks.service';
import { ShareButtonComponent } from '../share/share-button.component';
import { sharedLink, sharedPage } from '../share/share-content';
import { ArticleSelection } from './article-selection.service';

/** What a selection offers, in the bubble and the phone bar alike: a quote per network, and the share menu. */
@Component({
  selector: 'app-selection-actions',
  imports: [TranslocoPipe, IconButtonDirective, NetworkLogoComponent, ShareButtonComponent],
  templateUrl: './selection-actions.component.html',
  styleUrl: './selection-actions.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SelectionActionsComponent {
  readonly url = input.required<string>();
  readonly title = input('');

  private readonly selection = inject(ArticleSelection);
  private readonly postActions = inject(PostActions);
  protected readonly networks = inject(PostNetworks).connected;

  protected post(network: SocialNetworkName): void {
    const page = sharedPage(this.url(), this.title());
    this.postActions.postLink(network, sharedLink(page, this.selection.remembered()));
  }
}
