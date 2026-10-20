import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { EntryDto } from '../../models';
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
  readonly entry = input.required<EntryDto>();
}
