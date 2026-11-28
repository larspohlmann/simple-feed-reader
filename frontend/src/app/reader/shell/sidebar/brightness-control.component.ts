import { Component, computed, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { StepperBarComponent } from '../../../shared/stepper-bar/stepper-bar.component';
import { BrightnessService } from '../../../theme/brightness.service';

/** Moon, a solid accent progress bar, sun: the sidebar's brightness stepper (#832). */
@Component({
  selector: 'app-brightness-control',
  imports: [StepperBarComponent, TranslocoPipe],
  templateUrl: './brightness-control.component.html',
  styleUrl: './brightness-control.component.scss',
})
export class BrightnessControlComponent {
  readonly brightness = inject(BrightnessService);

  readonly signedStep = computed(() => {
    const step = this.brightness.step();
    return step > 0 ? `+${step}` : String(step);
  });
}
