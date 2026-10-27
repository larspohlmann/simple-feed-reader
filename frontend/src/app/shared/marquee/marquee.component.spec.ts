import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { MarqueeComponent } from './marquee.component';

@Component({
  imports: [MarqueeComponent],
  template: `<app-marquee><ng-template>A chapter title · and its track</ng-template></app-marquee>`,
})
class HostComponent {}

describe('MarqueeComponent', () => {
  let notify: () => void;

  beforeEach(() => {
    globalThis.ResizeObserver = class {
      constructor(callback: () => void) {
        notify = callback;
      }
      observe = jest.fn();
      disconnect = jest.fn();
    } as unknown as typeof ResizeObserver;
  });

  afterEach(() => {
    delete (globalThis as { ResizeObserver?: unknown }).ResizeObserver;
  });

  function render(available: number, needed: number): HTMLElement {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    const marquee: HTMLElement = fixture.nativeElement.querySelector('app-marquee');
    Object.defineProperty(marquee, 'clientWidth', { value: available });
    marquee.querySelector('.copy')!.getBoundingClientRect = () => ({ width: needed }) as DOMRect;
    notify();
    fixture.detectChanges();
    return marquee;
  }

  it('loops a line wider than its box behind a hidden repeat of itself', () => {
    const marquee = render(200, 290.4);

    expect(marquee.classList).toContain('overflowing');
    expect(marquee.style.getPropertyValue('--marquee-copy')).toBe('291px');
    expect(marquee.style.getPropertyValue('--marquee-duration')).toBe('14.3s');
    const repeat = marquee.querySelector('.repeat');
    expect(repeat?.getAttribute('aria-hidden')).toBe('true');
    expect(repeat?.textContent).toBe('A chapter title · and its track');
  });

  it('holds a line that fits still', () => {
    const marquee = render(200, 180);

    expect(marquee.classList).not.toContain('overflowing');
    expect(marquee.querySelector('.repeat')).toBeNull();
  });
});
