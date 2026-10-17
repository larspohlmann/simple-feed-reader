import { ChangeDetectionStrategy, Component, input } from '@angular/core';

/** The `n/N` pill that numbers a post within its thread. */
@Component({
  selector: 'app-thread-count',
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: '{{ position() }}/{{ total() }}',
  styleUrl: './thread-count.component.scss',
})
export class ThreadCountComponent {
  readonly position = input.required<number>();
  readonly total = input.required<number>();
}
