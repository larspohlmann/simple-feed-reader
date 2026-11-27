import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { IconComponent } from '../icon/icon.component';

@Component({
  selector: 'app-stepper-bar',
  imports: [IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './stepper-bar.component.html',
  styleUrl: './stepper-bar.component.scss',
})
export class StepperBarComponent {
  readonly step = input.required<number>();
  readonly min = input.required<number>();
  readonly max = input.required<number>();
  readonly decreaseIcon = input.required<string>();
  readonly increaseIcon = input.required<string>();
  readonly groupLabel = input.required<string>();
  readonly decreaseLabel = input.required<string>();
  readonly increaseLabel = input.required<string>();
  readonly resetLabel = input.required<string>();
  readonly readout = input.required<string>();
  readonly decreaseClass = input('decrease');
  readonly increaseClass = input('increase');

  readonly decrease = output<void>();
  readonly increase = output<void>();
  readonly resetStep = output<void>();

  readonly fillPercent = computed(
    () => ((this.step() - this.min()) / (this.max() - this.min())) * 100,
  );
}
