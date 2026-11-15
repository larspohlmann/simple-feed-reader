import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { LinkReaderNavigation } from '../shell/link-reader-navigation.service';
import { LinkCardDto } from './link-card';

/** `layer` opens the card's page over the open post; `alone` opens it in place of the list's post. */
export type LinkCardReaderOpening = 'layer' | 'alone';

/**
 * The scrim button over a link that opens its page in the app's reader (#1664), at the top end of the nearest
 * positioned ancestor; the ancestor makes room, while the host is `.available`, with the partial's `room` mixin.
 */
@Component({
  selector: 'app-open-in-reader',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './open-in-reader.component.html',
  styleUrl: './open-in-reader.component.scss',
  host: { '[class.available]': 'available' },
})
export class OpenInReaderComponent {
  readonly card = input.required<LinkCardDto>();
  readonly opening = input.required<LinkCardReaderOpening>();
  private readonly navigation = inject(LinkReaderNavigation, { optional: true });

  protected readonly available = this.navigation !== null;

  protected onOpenInReader(event: Event): void {
    event.stopPropagation();
    if (this.opening() === 'layer') this.navigation?.layer(this.card());
    else this.navigation?.openAlone(this.card());
  }
}
