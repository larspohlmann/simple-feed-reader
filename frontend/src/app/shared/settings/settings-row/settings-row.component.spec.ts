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
});
