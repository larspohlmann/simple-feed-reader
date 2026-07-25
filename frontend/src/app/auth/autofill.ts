// src/app/auth/autofill.ts
import { FormGroup } from '@angular/forms';

/** Copy what is actually in the inputs into the form model.
 *
 *  Angular binds to the `input` event. A password manager filling a form does
 *  not always dispatch one -- iOS is the reliable offender -- so the user looks
 *  at their own credentials on screen while the model is still empty, the form
 *  reads as invalid, and submitting does nothing. Reading the DOM once, at
 *  submit, closes that gap whatever mechanism did the filling.
 *
 *  Reported from an iPhone as "the button does nothing, not even a spinner",
 *  which is precisely what an invalid form produced before the guards started
 *  explaining themselves.
 */
export function adoptAutofilledValues(host: HTMLElement, form: FormGroup): void {
  for (const input of host.querySelectorAll<HTMLInputElement>('input[formControlName]')) {
    const name = input.getAttribute('formControlName');
    const control = name ? form.get(name) : null;
    if (control && input.value !== control.value) {
      control.setValue(input.value);
    }
  }
}
