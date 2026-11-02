import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { KeepSelectionDirective } from './keep-selection.directive';

@Component({
  imports: [KeepSelectionDirective],
  template: `
    <button appKeepSelection type="button">Keep</button>
    <button type="button">Plain</button>
  `,
})
class Host {}

describe('KeepSelectionDirective', () => {
  function pressed(label: string): boolean {
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const button = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('button'),
    ).find((candidate) => candidate.textContent === label)!;
    const press = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
    button.dispatchEvent(press);
    return press.defaultPrevented;
  }

  it('keeps the selection when the element is pressed', () => {
    expect(pressed('Keep')).toBe(true);
  });

  it('leaves an element without the attribute alone', () => {
    expect(pressed('Plain')).toBe(false);
  });
});
