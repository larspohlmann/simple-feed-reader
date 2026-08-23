import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SettingsStackComponent } from './settings-stack.component';

@Component({
  imports: [SettingsStackComponent],
  template: `
    <app-settings-stack>
      <div data-first>one</div>
      <div data-second>two</div>
    </app-settings-stack>
  `,
})
class HostComponent {}

describe('SettingsStackComponent', () => {
  async function render() {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('projects every child', async () => {
    const el = await render();

    expect(el.querySelector('app-settings-stack [data-first]')?.textContent).toBe('one');
    expect(el.querySelector('app-settings-stack [data-second]')?.textContent).toBe('two');
  });

  // The gap lives on the stack host, not on the children, so a child that is
  // another component's host element is spaced exactly like an inline one.
  // That is the whole point of the primitive: `app-settings-card +
  // app-settings-card` was a sibling selector and died at a host boundary
  // (#454). Asserting the children carry no spacing of their own is what stops
  // a future compensating margin from creeping back in.
  it('keeps its children free of their own spacing', async () => {
    const el = await render();
    const first = el.querySelector<HTMLElement>('[data-first]')!;

    expect(first.style.marginBlockStart).toBe('');
    expect(first.style.marginTop).toBe('');
  });
});
