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
    const element: HTMLElement = fixture.nativeElement;

    expect(element.querySelector('button[appIconButton]')?.classList.contains('ib')).toBe(true);
    // A button without the attribute stays untouched, so the class is opt-in.
    const plain = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent === 'Plain',
    );
    expect(plain?.classList.contains('ib')).toBe(false);
  });
});
