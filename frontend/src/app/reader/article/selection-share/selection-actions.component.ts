import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { SocialNetworkName } from '../../../core/social-network';
import { IconButtonDirective } from '../../../shared/icon-button/icon-button.directive';
import { NetworkLogoComponent } from '../../../shared/network-logo/network-logo.component';
import { LayoutService } from '../../layout.service';
import { PostActions } from '../composer/post-actions.service';
import { PostNetworks } from '../share/post-networks.service';
import { ShareButtonComponent } from '../share/share-button.component';
import { sharedLink, sharedPage } from '../share/share-content';
import { SHARE_RECORDER } from '../share/share-recorder';
import { ArticleSelection } from './article-selection.service';

/** What a selection offers, in the bubble and the phone bar alike: a quote per network, and the share menu. */
@Component({
  selector: 'app-selection-actions',
  imports: [TranslocoPipe, IconButtonDirective, NetworkLogoComponent, ShareButtonComponent],
  templateUrl: './selection-actions.component.html',
  styleUrl: './selection-actions.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.touch]': 'screen.isCoarse()' },
})
export class SelectionActionsComponent {
  readonly url = input.required<string>();
  readonly title = input('');

  private readonly postActions = inject(PostActions);
  private readonly recorder = inject(SHARE_RECORDER, { optional: true });
  protected readonly networks = inject(PostNetworks).connected;
  private readonly selection = inject(ArticleSelection);
  protected readonly screen = inject(LayoutService);
  protected readonly remembered = this.selection.remembered;

  protected post(network: SocialNetworkName): void {
    const page = sharedPage(this.url(), this.title());
    this.postActions
      .postLink(network, sharedLink(page, this.remembered()))
      .subscribe(() => this.recorder?.record());
    if (this.screen.isCoarse()) this.selection.releasePageSelection();
  }
}
