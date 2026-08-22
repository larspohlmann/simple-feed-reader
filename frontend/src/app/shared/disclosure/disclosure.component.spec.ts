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

@Component({
  imports: [DisclosureComponent],
  template: `
    <app-disclosure [label]="'Expert settings'" appearance="drill-in" [startOpen]="startOpen">
      <p class="projected">body</p>
    </app-disclosure>
  `,
})
class DrillInAppearanceHostComponent {
  startOpen = false;
}

@Component({
  imports: [DisclosureComponent],
  template: `
    <app-disclosure [label]="'Show fixed prompt'" (opened)="openedCount = openedCount + 1">
      <p class="projected">body</p>
    </app-disclosure>
  `,
})
class OpenedListenerHostComponent {
  openedCount = 0;
}

@Component({
  imports: [DisclosureComponent],
  template: `
    <app-disclosure [label]="'Show fixed prompt'" [startOpen]="startOpen">
      <p class="projected">body</p>
    </app-disclosure>
  `,
})
class StartOpenHostComponent {
  startOpen = true;
}

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

  async function renderDrillInAppearance() {
    await TestBed.configureTestingModule({
      imports: [DrillInAppearanceHostComponent],
    }).compileComponents();
    const fixture = TestBed.createComponent(DrillInAppearanceHostComponent);
    fixture.detectChanges();
    return { el: fixture.nativeElement as HTMLElement, fixture, host: fixture.componentInstance };
  }

  async function renderWithOpenedListener() {
    await TestBed.configureTestingModule({
      imports: [OpenedListenerHostComponent],
    }).compileComponents();
    const fixture = TestBed.createComponent(OpenedListenerHostComponent);
    fixture.detectChanges();
    return { el: fixture.nativeElement as HTMLElement, fixture, host: fixture.componentInstance };
  }

  async function renderStartOpen() {
    await TestBed.configureTestingModule({
      imports: [StartOpenHostComponent],
    }).compileComponents();
    const fixture = TestBed.createComponent(StartOpenHostComponent);
    fixture.detectChanges();
    return { el: fixture.nativeElement as HTMLElement, fixture, host: fixture.componentInstance };
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

  it('applies the drill-in appearance class when appearance is "drill-in"', async () => {
    const { el } = await renderDrillInAppearance();
    const summary = el.querySelector('summary') as HTMLElement;

    expect(summary.classList.contains('is-drill-in')).toBe(true);
  });

  it('does not apply the drill-in appearance class for the default pill appearance', async () => {
    const { el } = await render();
    const summary = el.querySelector('summary') as HTMLElement;

    expect(summary.classList.contains('is-drill-in')).toBe(false);
  });

  it('opens the drill-in details when startOpen is true', async () => {
    const { el, host, fixture } = await renderDrillInAppearance();
    const details = el.querySelector('details') as HTMLDetailsElement;
    expect(details.open).toBe(false);

    host.startOpen = true;
    fixture.detectChanges();

    expect(details.open).toBe(true);
  });

  it('announces when it is opened', async () => {
    const { el, host } = await renderWithOpenedListener();
    const details = el.querySelector('details') as HTMLDetailsElement;

    details.open = true;
    details.dispatchEvent(new Event('toggle'));

    expect(host.openedCount).toBe(1);
  });

  it('stays quiet when it is closed again', async () => {
    const { el, host } = await renderWithOpenedListener();
    const details = el.querySelector('details') as HTMLDetailsElement;

    details.open = true;
    details.dispatchEvent(new Event('toggle'));
    details.open = false;
    details.dispatchEvent(new Event('toggle'));

    expect(host.openedCount).toBe(1);
  });

  it('starts open when startOpen is true', async () => {
    const { el } = await renderStartOpen();
    const details = el.querySelector('details') as HTMLDetailsElement;

    expect(details.open).toBe(true);
  });

  it('does not force a reader-closed details back open on a later change detection', async () => {
    const { el, fixture } = await renderStartOpen();
    const details = el.querySelector('details') as HTMLDetailsElement;

    details.open = false;
    details.dispatchEvent(new Event('toggle'));
    // `startOpen` itself never changes value, so Angular's property binding
    // has nothing new to write -- a later change detection pass must not
    // re-assert `open` and fight the reader's own close.
    fixture.detectChanges();

    expect(details.open).toBe(false);
  });
});
