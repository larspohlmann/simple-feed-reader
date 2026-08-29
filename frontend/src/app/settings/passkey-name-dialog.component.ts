// src/app/settings/passkey-name-dialog.component.ts
import { Component, inject } from '@angular/core';
import {
  AbstractControl,
  NonNullableFormBuilder,
  ReactiveFormsModule,
  ValidationErrors,
  Validators,
} from '@angular/forms';
import { A11yModule } from '@angular/cdk/a11y';
import { DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { defaultPasskeyName } from '../core/passkey-device-name';
import { ButtonComponent } from '../shared/button/button.component';
import { FieldComponent } from '../shared/field/field.component';
import { OverlayPanelComponent } from '../shared/overlay-panel/overlay-panel.component';

/**
 * Names a passkey as part of adding it (#624 fix round 1 -- issue #624's
 * scope names "rename-on-create" explicitly; a fixed label for every
 * enrolment, which is what `PasskeysGroupComponent` shipped with before this
 * round, defeats the point of listing several credentials side by side).
 *
 * Pre-fills a device-derived default (`defaultPasskeyName`) rather than an
 * empty box -- the name's whole job is to remind the user which device this
 * is, and the device it is being created on is the one fact they reliably
 * know at this moment. The user can still edit it before confirming.
 *
 * A small dialog, not an inline row: the header's "Add a passkey" action does
 * not sit beside a row of its own to expand in place, and the enrolment it
 * leads to already opens the platform's own modal sheet, so one more small
 * modal step in front of it reads as one flow rather than a new surface.
 *
 * Returns the trimmed name on confirm, nothing on cancel. Triggering the
 * actual WebAuthn ceremony -- and telling a cancelled one apart from a real
 * failure -- stays `PasskeysGroupComponent.add()`'s job; this dialog only
 * collects a non-blank label.
 */
@Component({
  selector: 'app-passkey-name-dialog',
  imports: [
    A11yModule,
    ButtonComponent,
    FieldComponent,
    OverlayPanelComponent,
    ReactiveFormsModule,
    TranslocoPipe,
  ],
  templateUrl: './passkey-name-dialog.component.html',
  styleUrl: './passkey-name-dialog.component.scss',
})
export class PasskeyNameDialogComponent {
  readonly ref = inject<DialogRef<string>>(DialogRef);
  private readonly fb = inject(NonNullableFormBuilder);

  readonly form = this.fb.group({
    name: [defaultPasskeyName(navigator.userAgent), [notBlank, Validators.maxLength(100)]],
  });

  confirm(): void {
    if (this.form.invalid) return;
    this.ref.close(this.form.getRawValue().name.trim());
  }
}

/** `Validators.required` only rejects the empty string, so a name of pure
 *  whitespace would pass it, then arrive at the server trimmed to blank and
 *  rejected with a 422 the user cannot make sense of -- the exact failure
 *  mode this dialog exists to prevent. */
function notBlank(control: AbstractControl<string>): ValidationErrors | null {
  return control.value.trim() ? null : { blank: true };
}
