import { ChangeDetectionStrategy, Component, input, linkedSignal } from '@angular/core';
import { ProxiedImageDirective } from '../../shared/proxied-image/proxied-image.directive';
import { LinkCardDto } from './link-card';

/** `article` reads like #1499's card in a post's body; `block` is the magazine's, site line first. */
export type LinkCardLayout = 'article' | 'block';

@Component({
  selector: 'app-link-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ProxiedImageDirective],
  templateUrl: './link-card.component.html',
  styleUrl: './link-card.component.scss',
  host: {
    class: 'link-card',
    '[class.article]': "layout() === 'article'",
    '[class.block]': "layout() === 'block'",
  },
})
export class LinkCardComponent {
  readonly card = input.required<LinkCardDto>();
  readonly layout = input.required<LinkCardLayout>();
  protected readonly imageFailed = linkedSignal({ source: this.card, computation: () => false });
}
