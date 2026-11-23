import { OwnInstanceHrefDirective } from '../own-instance/own-instance-href.directive';
import { ChangeDetectionStrategy, Component, computed, input, linkedSignal } from '@angular/core';
import { ProxiedImageDirective } from '../../shared/proxied-image/proxied-image.directive';
import { LinkCardDto } from './link-card';
import { LinkCardReaderOpening, OpenInReaderComponent } from './open-in-reader.component';

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

@Component({
  selector: 'app-link-card',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [OwnInstanceHrefDirective, ProxiedImageDirective, OpenInReaderComponent],
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
  readonly siteLine = input<LinkCardSiteLine | null>(null);
  readonly opensInReader = input<LinkCardReaderOpening | null>(null);
  protected readonly imageFailed = linkedSignal({ source: this.card, computation: () => false });
  protected readonly siteLineFirst = computed(
    () => (this.siteLine() ?? SITE_LINE_OF_LAYOUT[this.layout()]) === 'first',
  );
}
