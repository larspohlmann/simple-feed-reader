// src/app/auth/autofill.spec.ts
import { FormControl, FormGroup } from '@angular/forms';
import { adoptAutofilledValues } from './autofill';

function host(html: string): HTMLElement {
  const el = document.createElement('div');
  el.innerHTML = html;
  return el;
}

describe('adoptAutofilledValues', () => {
  it('copies input values the form never heard about', () => {
    const form = new FormGroup({ email: new FormControl(''), password: new FormControl('') });
    const el = host(`
      <input type="email" formControlName="email" value="filled@example.com">
      <input type="password" formControlName="password" value="s3cret">
    `);

    adoptAutofilledValues(el, form);

    expect(form.value).toEqual({ email: 'filled@example.com', password: 's3cret' });
  });

  it('leaves values the user typed alone', () => {
    const form = new FormGroup({ email: new FormControl('typed@example.com') });
    const el = host('<input type="email" formControlName="email" value="typed@example.com">');
    const control = form.controls.email;
    const spy = jest.spyOn(control, 'setValue');

    adoptAutofilledValues(el, form);

    // Not merely equal afterwards -- untouched. Writing an identical value back
    // would mark the control dirty and re-run validators for nothing.
    expect(spy).not.toHaveBeenCalled();
  });

  it('ignores inputs that belong to no control', () => {
    const form = new FormGroup({ email: new FormControl('') });
    const el = host(`
      <input type="email" formControlName="email" value="a@b.c">
      <input type="text" formControlName="nonexistent" value="stray">
      <input type="text" value="unbound">
    `);

    expect(() => adoptAutofilledValues(el, form)).not.toThrow();
    expect(form.value).toEqual({ email: 'a@b.c' });
  });

  it('does nothing when the form has no inputs yet', () => {
    const form = new FormGroup({ email: new FormControl('') });
    expect(() => adoptAutofilledValues(host(''), form)).not.toThrow();
    expect(form.value).toEqual({ email: '' });
  });
});
