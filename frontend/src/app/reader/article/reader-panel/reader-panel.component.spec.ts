import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ReaderPanelComponent } from './reader-panel.component';

@Component({
  imports: [ReaderPanelComponent],
  template: `<app-reader-panel label="Chapters" [(open)]="open"
    ><ng-template><p class="inside">Rows</p></ng-template></app-reader-panel
  >`,
})
class HostComponent {
  readonly open = signal(false);
}

function render() {
  const fixture = TestBed.createComponent(HostComponent);
  fixture.detectChanges();
  const element = fixture.nativeElement as HTMLElement;
  const toggle = element.querySelector<HTMLButtonElement>('.reader-panel__toggle')!;
  return { fixture, element, toggle };
}

describe('ReaderPanelComponent', () => {
  it('starts collapsed, labelled by its toggle', () => {
    const { element, toggle } = render();

    expect(toggle.textContent!.trim()).toContain('Chapters');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(element.querySelector('.inside')).toBeNull();
  });

  it('shows its body once toggled, and tells the caller', () => {
    const { fixture, element, toggle } = render();

    toggle.click();
    fixture.detectChanges();

    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(element.querySelector('.inside')).not.toBeNull();
    expect(fixture.componentInstance.open()).toBe(true);
  });

  it('opens when the caller opens it', () => {
    const { fixture, element } = render();

    fixture.componentInstance.open.set(true);
    fixture.detectChanges();

    expect(element.querySelector('.inside')).not.toBeNull();
  });
});
