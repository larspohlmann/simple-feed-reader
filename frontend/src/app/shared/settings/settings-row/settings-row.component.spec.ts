import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SettingsRowComponent } from './settings-row.component';

@Component({
  imports: [SettingsRowComponent],
  template: `
    <app-settings-row title="T" description="D" [stackable]="true">
      <button class="ctl">x</button>
    </app-settings-row>
  `,
})
class HostComponent {}

@Component({
  imports: [SettingsRowComponent],
  template: `
    <app-settings-row title="T">
      <button class="ctl">x</button>
    </app-settings-row>
  `,
})
class NoDescriptionHostComponent {}

@Component({
  imports: [SettingsRowComponent],
  template: `
    <app-settings-row title="T">
      <span rowTitleTip class="tip">?</span>
      <button class="ctl">x</button>
    </app-settings-row>
  `,
})
class TitleTipHostComponent {}

@Component({
  imports: [SettingsRowComponent],
  template: `
    <app-settings-row title="Scraping">
      <span rowTitleTip class="badge" data-badge>Experimental</span>
      <button data-control>on</button>
    </app-settings-row>
  `,
})
class BadgeHostComponent {}

describe('SettingsRowComponent', () => {
  async function render() {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return { el: fixture.nativeElement as HTMLElement };
  }

  async function renderNoDescription() {
    await TestBed.configureTestingModule({
      imports: [NoDescriptionHostComponent],
    }).compileComponents();
    const fixture = TestBed.createComponent(NoDescriptionHostComponent);
    fixture.detectChanges();
    return { el: fixture.nativeElement as HTMLElement };
  }

  async function renderTitleTip() {
    await TestBed.configureTestingModule({
      imports: [TitleTipHostComponent],
    }).compileComponents();
    const fixture = TestBed.createComponent(TitleTipHostComponent);
    fixture.detectChanges();
    return { el: fixture.nativeElement as HTMLElement };
  }

  it('renders the title', async () => {
    const { el } = await render();
    expect(el.querySelector('.row-title')?.textContent).toContain('T');
  });

  it('renders the description', async () => {
    const { el } = await render();
    expect(el.querySelector('.row-desc')?.textContent).toContain('D');
  });

  it('projects the control into the control slot', async () => {
    const { el } = await render();
    expect(el.querySelector('.row-control .ctl')?.textContent).toBe('x');
  });

  it('applies the stackable class when stackable is true', async () => {
    const { el } = await render();
    expect(el.querySelector('.row')?.classList.contains('stackable')).toBe(true);
  });

  it('omits the description element when description is empty', async () => {
    const { el } = await renderNoDescription();
    expect(el.querySelector('.row-desc')).toBeNull();
  });

  it('does not apply the stackable class by default', async () => {
    const { el } = await renderNoDescription();
    expect(el.querySelector('.row')?.classList.contains('stackable')).toBe(false);
  });

  it('projects a title info-tip next to the title', async () => {
    const { el } = await renderTitleTip();
    expect(el.querySelector('.row-title .tip')?.textContent).toBe('?');
  });

  // The slot is positional, not typed: it is "an inline adornment after the
  // title". An info-tip was its first consumer; the Experimental badge on the
  // preferences page is its second (#547).
  it('places a badge in the title slot, after the title text', async () => {
    await TestBed.configureTestingModule({ imports: [BadgeHostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(BadgeHostComponent);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;

    expect(el.querySelector('.row-title [data-badge]')?.textContent).toBe('Experimental');
    expect(el.querySelector('.row-control [data-control]')).not.toBeNull();
  });
});
