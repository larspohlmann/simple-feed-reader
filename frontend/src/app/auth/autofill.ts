import { FormGroup } from '@angular/forms';

/** Copies what is actually in the inputs into the form model.
 *
 *  Angular binds to the `input` event, but a password manager filling a form
 *  does not always dispatch one -- iOS is the reliable offender -- so the
 *  model stays empty, the form reads as invalid, and submitting does nothing.
 *  Reading the DOM once, at submit, closes that gap regardless of what filled it.
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
