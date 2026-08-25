import {
  AfterContentInit,
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  inject,
  signal,
} from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../icon/icon.component';

/**
 * Wraps a password control and adds a button that reveals the secret while the
 * user types.
 *
 * Projects the control the way <app-field> does rather than owning it: the
 * input stays in the consumer's template with its own formControlName or
 * [value] binding, its autocomplete, name and data-testid. The component
 * reaches the projected input once and only ever flips its `type`, so revealing
 * a secret touches nothing the form model — or the autofill recovery in
 * auth/autofill.ts, which reads `input[formControlName]` straight from the DOM —
 * depends on. The projected input's right padding and width live in
 * styles/_controls.scss, because ViewEncapsulation does not reach projected
 * content.
 */
@Component({
  selector: 'app-password-input',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './password-input.component.html',
  styleUrl: './password-input.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class PasswordInputComponent implements AfterContentInit {
  private readonly host = inject(ElementRef).nativeElement as HTMLElement;
  private control: HTMLInputElement | null = null;

  protected readonly revealed = signal(false);

  ngAfterContentInit(): void {
    this.control = this.host.querySelector('input');
  }

  protected toggle(): void {
    const control = this.control;
    if (!control) {
      return;
    }

    this.revealed.update((shown) => !shown);
    control.type = this.revealed() ? 'text' : 'password';
    control.focus();
  }
}
