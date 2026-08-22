import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { SettingsSaveBarComponent } from './save-bar.component';

@Component({
  imports: [SettingsSaveBarComponent],
  template: `
    <app-settings-save-bar
      [dirty]="dirty()"
      [saving]="saving()"
      saveLabel="Save changes"
      resetLabel="Reset"
      unsavedLabel="Unsaved changes"
      (save)="saveCount = saveCount + 1"
      (reset)="resetCount = resetCount + 1"
    />
  `,
})
class HostComponent {
  readonly dirty = signal(false);
  readonly saving = signal(false);
  saveCount = 0;
  resetCount = 0;
}

describe('SettingsSaveBarComponent', () => {
  async function render() {
    await TestBed.configureTestingModule({ imports: [HostComponent] }).compileComponents();
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return { fixture, host: fixture.componentInstance, el: fixture.nativeElement as HTMLElement };
  }

  const saveButton = (el: HTMLElement) => el.querySelector('button.primary') as HTMLButtonElement;
  const resetButton = (el: HTMLElement) => el.querySelector('button.ghost') as HTMLButtonElement;

  it('shows the unsaved indicator and enables Save when dirty', async () => {
    const { fixture, host, el } = await render();
    host.dirty.set(true);
    fixture.detectChanges();

    expect(el.querySelector('.unsaved')).not.toBeNull();
    expect(saveButton(el).disabled).toBe(false);
  });

  it('emits save when Save is clicked while dirty', async () => {
    const { fixture, host, el } = await render();
    host.dirty.set(true);
    fixture.detectChanges();

    saveButton(el).click();

    expect(host.saveCount).toBe(1);
  });

  it('hides the unsaved indicator and disables Save when not dirty', async () => {
    const { el } = await render();

    expect(el.querySelector('.unsaved')).toBeNull();
    expect(saveButton(el).disabled).toBe(true);
  });

  it('emits reset when Reset is clicked', async () => {
    const { host, el } = await render();

    resetButton(el).click();

    expect(host.resetCount).toBe(1);
  });

  it('shows a spinner and disables Save while saving', async () => {
    const { fixture, host, el } = await render();
    host.dirty.set(true);
    host.saving.set(true);
    fixture.detectChanges();

    expect(el.querySelector('app-spinner')).not.toBeNull();
    expect(saveButton(el).disabled).toBe(true);
  });
});
