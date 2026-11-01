import { NgTemplateOutlet } from '@angular/common';
import { ChangeDetectionStrategy, Component, computed, input, signal } from '@angular/core';
import { ProxiedImageDirective } from '../../../shared/proxied-image/proxied-image.directive';
import { ComposerSubjectDto } from '../../entry-social';

/** What a post answers, quotes or shares: the post with the link card it carries, or the shared page's card. */
@Component({
  selector: 'app-composer-subject',
  imports: [NgTemplateOutlet, ProxiedImageDirective],
  templateUrl: './composer-subject.component.html',
  styleUrl: './composer-subject.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ComposerSubjectComponent {
  readonly subject = input.required<ComposerSubjectDto>();

  protected readonly post = computed(() => {
    const subject = this.subject();
    return subject.kind === 'post' ? subject : null;
  });
  protected readonly imageFailed = signal(false);
}
