import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import type { ComposeTarget } from './composer-dialog.component';

/** What a post responds to or shares: the quoted post, or the article's compact card. */
@Component({
  selector: 'app-compose-target',
  imports: [ProxiedImageDirective],
  templateUrl: './compose-target.component.html',
  styleUrl: './compose-target.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ComposeTargetComponent {
  readonly target = input.required<ComposeTarget>();

  protected readonly article = computed(() => {
    const target = this.target();
    return target.kind === 'link' ? target : null;
  });
  protected readonly response = computed(() => {
    const target = this.target();
    return target.kind === 'link' ? null : target;
  });
  protected readonly imageFailed = signal(false);
}
