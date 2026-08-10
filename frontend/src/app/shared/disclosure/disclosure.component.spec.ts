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

@Component({
  imports: [DisclosureComponent],
  template: `
    <app-disclosure [label]="'Show fixed prompt'">
      <span summary class="rich">Hi</span>
      <p class="projected">body</p>
    </app-disclosure>
  `,
})
class ProjectedSummaryHostComponent {}

@Component({
  imports: [DisclosureComponent],
  template: `
    <app-disclosure [label]="'Show fixed prompt'" appearance="row">
      <p class="projected">body</p>
    </app-disclosure>
  `,
})
class RowAppearanceHostComponent {}

describe('DisclosureComponent', () => {
  async function render() {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return { el: fixture.nativeElement as HTMLElement, fixture };
  }

  async function renderProjectedSummary() {
    await TestBed.configureTestingModule({
      imports: [ProjectedSummaryHostComponent],
    }).compileComponents();
    const fixture = TestBed.createComponent(ProjectedSummaryHostComponent);
    fixture.detectChanges();
    return { el: fixture.nativeElement as HTMLElement, fixture };
  }

  async function renderRowAppearance() {
    await TestBed.configureTestingModule({
      imports: [RowAppearanceHostComponent],
    }).compileComponents();
    const fixture = TestBed.createComponent(RowAppearanceHostComponent);
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

  it('renders a projected summary instead of the label', async () => {
    const { el } = await renderProjectedSummary();
    const summary = el.querySelector('summary') as HTMLElement;

    expect(summary.querySelector('.rich')?.textContent).toBe('Hi');
    expect(summary.textContent?.trim()).toBe('Hi');
    expect(summary.textContent).not.toContain('Show fixed prompt');
  });

  it('applies the row appearance class when appearance is "row"', async () => {
    const { el } = await renderRowAppearance();
    const summary = el.querySelector('summary') as HTMLElement;

    expect(summary.classList.contains('is-row')).toBe(true);
  });

  it('does not apply the row appearance class for the default pill appearance', async () => {
    const { el } = await render();
    const summary = el.querySelector('summary') as HTMLElement;

    expect(summary.classList.contains('is-row')).toBe(false);
  });
});
