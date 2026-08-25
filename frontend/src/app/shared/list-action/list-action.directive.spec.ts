import { TestBed } from '@angular/core/testing';
import { Component } from '@angular/core';
import { ListActionDirective } from './list-action.directive';

@Component({
  imports: [ListActionDirective],
  template: `
    <a appListAction href="#">Edit</a>
    <button appListAction type="button">Refresh</button>
    <button type="button">Plain</button>
  `,
})
class Host {}

describe('ListActionDirective', () => {
  it('stamps the shared `list-action` class on both an anchor and a button', async () => {
    await TestBed.configureTestingModule({ imports: [Host] }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    const el: HTMLElement = fixture.nativeElement;

    expect(el.querySelector('a')?.classList.contains('list-action')).toBe(true);
    expect(el.querySelector('button[appListAction]')?.classList.contains('list-action')).toBe(true);
    // A button without the attribute stays untouched, so the class is opt-in.
    const plain = Array.from(el.querySelectorAll('button')).find((b) => b.textContent === 'Plain');
    expect(plain?.classList.contains('list-action')).toBe(false);
  });
});
