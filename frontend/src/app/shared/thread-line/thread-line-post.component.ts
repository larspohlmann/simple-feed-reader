import { ChangeDetectionStrategy, Component } from '@angular/core';

/** A post on the thread line: its dot, the line down to the next post, and its content. */
@Component({
  selector: 'app-thread-line-post',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { role: 'listitem' },
  template:
    '<span class="rail" aria-hidden="true"></span><div class="content"><ng-content /></div>',
  styleUrl: './thread-line-post.component.scss',
})
export class ThreadLinePostComponent {}
