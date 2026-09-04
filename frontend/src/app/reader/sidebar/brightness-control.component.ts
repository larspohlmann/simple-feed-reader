import { Component, computed, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { BrightnessService } from '../../theme/brightness.service';

/** Moon, a solid accent progress bar, sun: the sidebar's brightness stepper (#832). */
@Component({
  selector: 'app-brightness-control',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './brightness-control.component.html',
  styleUrl: './brightness-control.component.scss',
})
export class BrightnessControlComponent {
  readonly brightness = inject(BrightnessService);

  readonly atMin = computed(() => this.brightness.step() <= this.brightness.min());
  readonly atMax = computed(() => this.brightness.step() >= this.brightness.max());

  /** How full the bar is: emptiest at the darkest step, full at the brightest. */
  readonly fillPercent = computed(() => {
    const min = this.brightness.min();
    return ((this.brightness.step() - min) / (this.brightness.max() - min)) * 100;
  });

  readonly signedStep = computed(() => {
    const step = this.brightness.step();
    return step > 0 ? `+${step}` : String(step);
  });
}
