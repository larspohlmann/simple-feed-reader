import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  linkedSignal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { ProxiedImageDirective } from '../../shared/proxied-image/proxied-image.directive';
import { LinkReaderNavigation } from '../shell/link-reader-navigation.service';
import { LinkCardDto } from './link-card';

/**
 * `article` reads like #1499's card in a post's body, site line last; `block` is the magazine's,
 * site line first.
 */
export type LinkCardLayout = 'article' | 'block';

/** A quoted post's card keeps its site line last in either layout, as in the Bluesky app (#1974). */
export type LinkCardSiteLine = 'first' | 'last';

const SITE_LINE_OF_LAYOUT: Record<LinkCardLayout, LinkCardSiteLine> = {
  article: 'last',
  block: 'first',
};

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
  readonly siteLine = input<LinkCardSiteLine | null>(null);
  readonly opensInReader = input<LinkCardReaderOpening | null>(null);
  private readonly navigation = inject(LinkReaderNavigation, { optional: true });
  protected readonly imageFailed = linkedSignal({ source: this.card, computation: () => false });
  protected readonly siteLineFirst = computed(
    () => (this.siteLine() ?? SITE_LINE_OF_LAYOUT[this.layout()]) === 'first',
  );

  protected openable(): boolean {
    return this.opensInReader() !== null && this.navigation !== null;
  }

  protected onOpenInReader(event: Event): void {
    event.stopPropagation();
    if (this.opensInReader() === 'layer') this.navigation?.layer(this.card());
    else this.navigation?.openAlone(this.card());
  }
}
