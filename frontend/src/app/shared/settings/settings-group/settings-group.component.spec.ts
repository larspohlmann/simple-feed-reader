import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SettingsGroupComponent } from './settings-group.component';

@Component({
  imports: [SettingsGroupComponent],
  template: `
    <app-settings-group icon="smart_toy" title="For You" caption="How your feed is built">
      <div data-projected>row</div>
    </app-settings-group>
  `,
})
class HostComponent {}

@Component({
  imports: [SettingsGroupComponent],
  template: `
    <app-settings-group icon="smart_toy" title="For You">
      <div data-projected>row</div>
    </app-settings-group>
  `,
})
class NoCaptionHostComponent {}

@Component({
  imports: [SettingsGroupComponent],
  template: `
    <app-settings-group icon="sell" title="Tags">
      <button groupActions data-action>New tag</button>
      <div data-projected>row</div>
    </app-settings-group>
  `,
})
class ActionsHostComponent {}

describe('SettingsGroupComponent', () => {
  async function render() {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return { el: fixture.nativeElement as HTMLElement, fixture };
  }

  async function renderNoCaption() {
    await TestBed.configureTestingModule({ imports: [NoCaptionHostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(NoCaptionHostComponent);
    fixture.detectChanges();
    return { el: fixture.nativeElement as HTMLElement, fixture };
  }

  it('renders the title', async () => {
    const { el } = await render();
    expect(el.querySelector('.g-title')?.textContent).toContain('For You');
  });

  it('renders the caption when provided', async () => {
    const { el } = await render();
    expect(el.querySelector('.g-caption')?.textContent).toContain('How your feed is built');
  });

  it('renders an icon in the group header', async () => {
    const { el } = await render();
    expect(el.querySelector('.g-icon app-icon')).not.toBeNull();
  });

  it('projects its content inside the panel', async () => {
    const { el } = await render();
    const projected = el.querySelector('.panel [data-projected]');
    expect(projected?.textContent).toBe('row');
  });

  it('omits the caption element when no caption is given', async () => {
    const { el } = await renderNoCaption();
    expect(el.querySelector('.g-caption')).toBeNull();
  });

  it('projects a groupActions element into the header, not the panel', async () => {
    await TestBed.configureTestingModule({ imports: [ActionsHostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(ActionsHostComponent);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;

    expect(el.querySelector('.g-head [data-action]')).not.toBeNull();
    expect(el.querySelector('.panel [data-action]')).toBeNull();
  });

  it('still projects the body into the panel when actions are present', async () => {
    await TestBed.configureTestingModule({ imports: [ActionsHostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(ActionsHostComponent);
    fixture.detectChanges();
    const el = fixture.nativeElement as HTMLElement;

    expect(el.querySelector('.panel [data-projected]')?.textContent).toBe('row');
  });
});
