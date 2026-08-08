import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { DisclosureComponent } from './disclosure.component';

@Component({
  imports: [DisclosureComponent],
  template: `
    <app-disclosure [label]="'Show fixed prompt'">
      <p class="projected">body</p>
    </app-disclosure>
  `,
})
class HostComponent {}

describe('DisclosureComponent', () => {
  async function render() {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return { el: fixture.nativeElement as HTMLElement, fixture };
  }

  it('renders the label in the summary', async () => {
    const { el } = await render();
    expect(el.querySelector('summary')?.textContent?.trim()).toBe('Show fixed prompt');
  });

  it('projects its content inside the details', async () => {
    const { el } = await render();
    const projected = el.querySelector('details .projected');
    expect(projected?.textContent).toBe('body');
  });

  it('starts closed', async () => {
    const { el } = await render();
    const details = el.querySelector('details') as HTMLDetailsElement;
    expect(details.open).toBe(false);
  });

  it('toggles open via a native click on the summary', async () => {
    const { el, fixture } = await render();
    const details = el.querySelector('details') as HTMLDetailsElement;
    const summary = el.querySelector('summary') as HTMLElement;

    summary.click();
    fixture.detectChanges();

    expect(details.open).toBe(true);
  });
});
