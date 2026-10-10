import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { LinkCardDto } from '../../link-card';

/** Unstyled on purpose: the reader's `.content .link-card` rules (#1499) dress the cards. */
@Component({
  selector: 'app-link-cards',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ProxiedImageDirective],
  templateUrl: './link-cards.component.html',
  styleUrl: './link-cards.component.scss',
})
export class LinkCardsComponent {
  readonly cards = input.required<LinkCardDto[]>();
}
