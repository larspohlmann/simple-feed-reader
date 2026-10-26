import { ChangeDetectionStrategy, Component, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';

@Component({
  selector: 'app-alt-text',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe],
  templateUrl: './alt-text.component.html',
  styleUrl: './alt-text.component.scss',
  host: { '(keydown.escape)': 'close($event)' },
})
export class AltTextComponent {
  readonly text = input.required<string>();
  protected readonly open = signal(false);

  protected toggle(event: Event): void {
    event.stopPropagation();
    this.open.update((open) => !open);
  }

  protected close(event: Event): void {
    if (!this.open()) return;
    event.stopPropagation();
    this.open.set(false);
  }
}
