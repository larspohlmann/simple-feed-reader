import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { ComposerTargetDto } from '../../entry-social';

/** What a post answers, quotes or shares: the post with the link card it carries, or the shared page's card. */
@Component({
  selector: 'app-compose-target',
  imports: [NgTemplateOutlet, ProxiedImageDirective],
  templateUrl: './compose-target.component.html',
  styleUrl: './compose-target.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ComposeTargetComponent {
  readonly target = input.required<ComposerTargetDto>();

  protected readonly post = computed(() => {
    const target = this.target();
    return target.kind === 'post' ? target : null;
  });
  protected readonly card = computed(() => this.target().card);
  protected readonly imageFailed = signal(false);
}
