import { Component, computed, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { BRIGHTNESS_CELLS } from '../../theme/brightness';
import { BrightnessService } from '../../theme/brightness.service';

/** Small sun, a seven-cell bar, big sun: the sidebar's brightness stepper (#832). */
@Component({
  selector: 'app-brightness-control',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './brightness-control.component.html',
  styleUrl: './brightness-control.component.scss',
})
export class BrightnessControlComponent {
  readonly brightness = inject(BrightnessService);
  readonly cells = BRIGHTNESS_CELLS;

  readonly atMin = computed(() => this.brightness.step() <= this.brightness.min);
  readonly atMax = computed(() => this.brightness.step() >= this.brightness.max());

  readonly signedStep = computed(() => {
    const step = this.brightness.step();
    return step > 0 ? `+${step}` : String(step);
  });
}
