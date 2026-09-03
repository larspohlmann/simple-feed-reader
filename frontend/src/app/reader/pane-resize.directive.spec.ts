import { Component, viewChild, ElementRef } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { DEFAULT_LIST_PERCENT, MAX_LIST_PERCENT, MIN_LIST_PERCENT } from './pane-split';
import { PaneResizeDirective } from './pane-resize.directive';
import { PaneSplitService } from './pane-split.service';

@Component({
  imports: [PaneResizeDirective],
  template: `
    <div class="main" #main>
      <div class="handle" [appPaneResize]="main"></div>
    </div>
  `,
})
class HostComponent {
  readonly main = viewChild.required<ElementRef<HTMLElement>>('main');
}

const RECT = { left: 0, top: 0, width: 1000, height: 500, right: 1000, bottom: 500 } as DOMRect;

describe('PaneResizeDirective', () => {
  let fixture: ComponentFixture<HostComponent>;
  let split: PaneSplitService;
  let container: HTMLElement;
  let handle: HTMLElement;

  const pointer = (type: string, clientX: number): MouseEvent =>
    new MouseEvent(type, { clientX, bubbles: true });

  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({ imports: [HostComponent] });
    fixture = TestBed.createComponent(HostComponent);
    split = TestBed.inject(PaneSplitService);
    fixture.detectChanges();
    container = fixture.debugElement.query(By.css('.main')).nativeElement as HTMLElement;
    container.getBoundingClientRect = () => RECT;
    handle = fixture.debugElement.query(By.directive(PaneResizeDirective))
      .nativeElement as HTMLElement;
  });

  it('sets the ARIA separator contract on init', () => {
    expect(handle.getAttribute('role')).toBe('separator');
    expect(handle.getAttribute('aria-orientation')).toBe('vertical');
    expect(handle.getAttribute('tabindex')).toBe('0');
    expect(handle.getAttribute('aria-valuemin')).toBe(String(MIN_LIST_PERCENT));
    expect(handle.getAttribute('aria-valuemax')).toBe(String(MAX_LIST_PERCENT));
    expect(handle.getAttribute('aria-valuenow')).toBe(String(DEFAULT_LIST_PERCENT));
  });

  it('writes --list-width on the container from the service width on init', () => {
    expect(container.style.getPropertyValue('--list-width')).toBe(`${DEFAULT_LIST_PERCENT}%`);
  });

  it('does not preventDefault on pointerdown, so the browser still fires dblclick', () => {
    // preventDefault on pointerdown would suppress the compatibility dblclick
    // event, silently breaking double-click-to-reset (an e2e caught it once).
    const down = new MouseEvent('pointerdown', { clientX: 250, bubbles: true, cancelable: true });
    handle.dispatchEvent(down);
    expect(down.defaultPrevented).toBe(false);
  });

  it('commits and persists the dragged percent on pointerup', () => {
    handle.dispatchEvent(pointer('pointerdown', 250));
    handle.dispatchEvent(pointer('pointerup', 250));
    expect(split.width()).toBe(25);
    expect(localStorage.getItem('sfr.paneSplit')).toBe('25');
  });

  it('clamps a drag past the band to the minimum so neither pane collapses', () => {
    handle.dispatchEvent(pointer('pointerdown', 500));
    handle.dispatchEvent(pointer('pointerup', 100));
    expect(split.width()).toBe(MIN_LIST_PERCENT);
  });

  it('resets to the default on double-click and persists it', () => {
    split.set(60);
    handle.dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));
    expect(split.width()).toBe(DEFAULT_LIST_PERCENT);
    expect(localStorage.getItem('sfr.paneSplit')).toBe(String(DEFAULT_LIST_PERCENT));
  });

  it('steps the width with ArrowRight and ArrowLeft and clamps at the edges', () => {
    handle.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    expect(split.width()).toBe(DEFAULT_LIST_PERCENT + 2);
    handle.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
    expect(split.width()).toBe(DEFAULT_LIST_PERCENT);

    split.set(MAX_LIST_PERCENT);
    handle.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
    expect(split.width()).toBe(MAX_LIST_PERCENT);

    split.set(MIN_LIST_PERCENT);
    handle.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
    expect(split.width()).toBe(MIN_LIST_PERCENT);
  });

  it('ends the drag and commits the width on pointercancel', () => {
    handle.dispatchEvent(pointer('pointerdown', 250));
    handle.dispatchEvent(pointer('pointercancel', 700));
    expect(split.width()).toBe(70);
    expect(localStorage.getItem('sfr.paneSplit')).toBe('70');
  });

  it('updates aria-valuenow after a commit', () => {
    handle.dispatchEvent(pointer('pointerdown', 600));
    handle.dispatchEvent(pointer('pointerup', 600));
    fixture.detectChanges();
    expect(handle.getAttribute('aria-valuenow')).toBe('60');
  });
});
