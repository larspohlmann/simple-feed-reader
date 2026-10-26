import { ChangeDetectionStrategy, Component, input, signal } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { DismissOnOutsideDirective } from '../../../shared/dismiss-on-outside.directive';

@Component({
  selector: 'app-alt-text',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [DismissOnOutsideDirective, TranslocoPipe],
  templateUrl: './alt-text.component.html',
  styleUrl: './alt-text.component.scss',
})
export class AltTextComponent {
  readonly text = input.required<string>();
  protected readonly open = signal(false);

  protected toggle(event: Event): void {
    event.stopPropagation();
    this.open.update((open) => !open);
  }
}
