import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { EntryDto } from '../../models';
import { LinkCardComponent } from '../../link-card/link-card.component';
import { LinkCardPlaceholderComponent } from '../../link-card/link-card-placeholder.component';
import { LinkReaderNavigation } from '../../shell/link-reader-navigation.service';

@Component({
  selector: 'app-link-cards',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [LinkCardComponent, LinkCardPlaceholderComponent],
  templateUrl: './link-cards.component.html',
  styleUrl: './link-cards.component.scss',
})
export class LinkCardsComponent {
  readonly entry = input.required<EntryDto>();
  protected readonly navigation = inject(LinkReaderNavigation);
}
