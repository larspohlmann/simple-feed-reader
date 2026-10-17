import { ChangeDetectionStrategy, Component } from '@angular/core';

/** One author's posts stacked on a thread line; each post is an `<app-thread-line-post>`. */
@Component({
  selector: 'app-thread-line',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { role: 'list' },
  template: '<ng-content />',
  styleUrl: './thread-line.component.scss',
})
export class ThreadLineComponent {}
