import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { LinkCardDto } from '../../link-card';
import { LinkCardPlaceholderComponent } from '../../link-card-placeholder/link-card-placeholder.component';

/** Unstyled on purpose: the reader's `.link-card` rules (#1499) dress the cards. */
@Component({
  selector: 'app-link-cards',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ProxiedImageDirective, LinkCardPlaceholderComponent],
  templateUrl: './link-cards.component.html',
  styleUrl: './link-cards.component.scss',
})
export class LinkCardsComponent {
  readonly cards = input.required<LinkCardDto[]>();
  readonly pending = input(false);
}
