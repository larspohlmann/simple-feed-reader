import { ChangeDetectionStrategy, Component, input, linkedSignal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';

const OPEN = 'reader-narration--open';

/** Shows or hides a machine-generated narration player in the body (#903, #1889). */
@Component({
  selector: 'app-narration-toggle',
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './narration-toggle.component.html',
  styleUrl: './narration-toggle.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.open]': 'open()' },
})
export class NarrationToggleComponent {
  readonly player = input.required<HTMLAudioElement>();

  protected readonly open = linkedSignal(() => this.player().classList.contains(OPEN));

  protected toggle(): void {
    this.open.set(this.player().classList.toggle(OPEN));
  }
}
