import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import type { LinkComposeTarget, ShownTarget } from './composer-dialog.component';

type CompactCard = Pick<LinkComposeTarget, 'title' | 'site' | 'imageUrl'>;

/** What a post responds to or shares: the quoted post with the link card it carries, or the article's compact card. */
@Component({
  selector: 'app-compose-target',
  imports: [NgTemplateOutlet, ProxiedImageDirective],
  templateUrl: './compose-target.component.html',
  styleUrl: './compose-target.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ComposeTargetComponent {
  readonly target = input.required<ShownTarget>();

  protected readonly response = computed(() => {
    const target = this.target();
    return target.kind === 'link' ? null : target;
  });
  protected readonly card = computed<CompactCard | null>(() => {
    const target = this.target();
    if (target.kind === 'link') return target;
    const card = target.card;
    return card === null
      ? null
      : { title: card.title, site: card.siteName, imageUrl: card.imageUrl };
  });
  protected readonly imageFailed = signal(false);
}
