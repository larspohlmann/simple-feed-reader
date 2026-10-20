import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';

@Component({
  selector: 'app-masked-logo',
  template: '',
  styleUrl: './masked-logo.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    'aria-hidden': 'true',
    '[style.background-color]': 'color()',
    '[style.mask-image]': 'maskImage()',
    '[style.-webkit-mask-image]': 'maskImage()',
  },
})
export class MaskedLogoComponent {
  readonly src = input.required<string>();
  readonly color = input<string>();

  protected readonly maskImage = computed(() => `url(${this.src()})`);
}
