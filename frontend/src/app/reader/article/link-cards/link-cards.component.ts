import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { LinkCardDto } from '../../link-card/link-card';
import { LinkCardComponent } from '../../link-card/link-card.component';
import { LinkCardPlaceholderComponent } from '../../link-card/link-card-placeholder.component';

@Component({
  selector: 'app-link-cards',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [LinkCardComponent, LinkCardPlaceholderComponent],
  templateUrl: './link-cards.component.html',
  styleUrl: './link-cards.component.scss',
})
export class LinkCardsComponent {
  readonly cards = input.required<LinkCardDto[]>();
  readonly pending = input(false);
}
