import { ChangeDetectionStrategy, Component, input, linkedSignal, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { ProxiedImageDirective } from '../../shared/proxied-image/proxied-image.directive';
import { LinkCardDto } from './link-card';

/** `article` reads like #1499's card in a post's body; `block` is the magazine's, site line first. */
export type LinkCardLayout = 'article' | 'block';

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
    '[class.openable]': 'openableInReader()',
  },
})
export class LinkCardComponent {
  readonly card = input.required<LinkCardDto>();
  readonly layout = input.required<LinkCardLayout>();
  readonly openableInReader = input(false);
  readonly openInReader = output<void>();
  protected readonly imageFailed = linkedSignal({ source: this.card, computation: () => false });

  protected onOpenInReader(event: Event): void {
    event.stopPropagation();
    this.openInReader.emit();
  }
}
