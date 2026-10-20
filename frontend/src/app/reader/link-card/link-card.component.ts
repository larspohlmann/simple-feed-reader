import { ChangeDetectionStrategy, Component, inject, input, linkedSignal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { ProxiedImageDirective } from '../../shared/proxied-image/proxied-image.directive';
import { LinkReaderNavigation } from '../shell/link-reader-navigation.service';
import { LinkCardDto } from './link-card';

/** `article` reads like #1499's card in a post's body; `block` is the magazine's, site line first. */
export type LinkCardLayout = 'article' | 'block';

/** `layer` opens the card's page over the open post; `alone` opens it in place of the list's post. */
export type LinkCardReaderOpening = 'layer' | 'alone';

@Component({
  selector: 'app-link-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [ProxiedImageDirective, IconComponent, TranslocoPipe],
  templateUrl: './link-card.component.html',
  styleUrl: './link-card.component.scss',
  host: {
    class: 'link-card',
    '[class.article]': "layout() === 'article'",
    '[class.block]': "layout() === 'block'",
    '[class.openable]': 'openable()',
  },
})
export class LinkCardComponent {
  readonly card = input.required<LinkCardDto>();
  readonly layout = input.required<LinkCardLayout>();
  readonly opensInReader = input<LinkCardReaderOpening | null>(null);
  private readonly navigation = inject(LinkReaderNavigation, { optional: true });
  protected readonly imageFailed = linkedSignal({ source: this.card, computation: () => false });

  protected openable(): boolean {
    return this.opensInReader() !== null && this.navigation !== null;
  }

  protected onOpenInReader(event: Event): void {
    event.stopPropagation();
    if (this.opensInReader() === 'layer') this.navigation?.layer(this.card());
    else this.navigation?.openAlone(this.card());
  }
}
