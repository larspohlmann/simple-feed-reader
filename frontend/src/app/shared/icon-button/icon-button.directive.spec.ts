import { TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { IconButtonDirective } from './icon-button.directive';

@Component({
  imports: [IconButtonDirective],
  template: `
    <button appIconButton type="button">Edit</button>
    <button type="button">Plain</button>
  `,
})
class Host {}

describe('IconButtonDirective', () => {
  it('stamps the shared `ib` class on the button that opts in', async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;

    expect(el.querySelector('button[appIconButton]')?.classList.contains('ib')).toBe(true);
    // A button without the attribute stays untouched, so the class is opt-in.
    const plain = Array.from(el.querySelectorAll('button')).find((b) => b.textContent === 'Plain');
    expect(plain?.classList.contains('ib')).toBe(false);
  });
});
