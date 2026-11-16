import { ChangeDetectionStrategy, Component, input } from '@angular/core';

@Component({
  selector: 'app-count-pill',
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './count-pill.component.html',
  styleUrl: './count-pill.component.scss',
})
export class CountPillComponent {
  readonly count = input.required<number>();
}
