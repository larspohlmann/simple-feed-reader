import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import type { ScrollHost } from '../scroll-host/scroll-host';
import { elementScrollHost } from '../../../testing/scroll-testing';
import { ProgressRailComponent } from './progress-rail.component';
import { ScrollProgressRail } from './scroll-progress-rail';

describe('ProgressRailComponent', () => {
  function mount(isWide: boolean) {
    const progress = TestBed.runInInjectionContext(
      () =>
        new ScrollProgressRail({
          isWide: signal(isWide),
          scroller: () => undefined,
          contentBottom: () => null,
          layout: signal(0),
        }),
    );
    const fixture = TestBed.createComponent(ProgressRailComponent);
    fixture.componentRef.setInput('progress', progress);
    fixture.detectChanges();
    return { host: fixture.nativeElement as HTMLElement, progress, fixture };
  }

  it('is decorative and idle until the content overflows', () => {
    const { host, progress, fixture } = mount(false);
    expect(host.getAttribute('aria-hidden')).toBe('true');
    expect(host.classList).toContain('idle');

    progress.overflows.set(true);
    fixture.detectChanges();

    expect(host.classList).not.toContain('idle');
  });

  it('stands up on a phone and lies along the bottom when wide', () => {
    expect(mount(false).host.classList).not.toContain('horizontal');
    expect(mount(true).host.classList).toContain('horizontal');
  });
});

describe('ScrollProgressRail following its scroller', () => {
  function scrollerElement(scrollTop: number): HTMLElement {
    const element = document.createElement('div');
    Object.defineProperty(element, 'clientHeight', { value: 100 });
    Object.defineProperty(element, 'scrollHeight', { value: 1000 });
    element.scrollTop = scrollTop;
    return element;
  }

  function setup() {
    const first = scrollerElement(0);
    const second = scrollerElement(0);
    const host = signal<ScrollHost | undefined>(elementScrollHost(first));
    const progress = TestBed.runInInjectionContext(
      () =>
        new ScrollProgressRail({
          isWide: signal(false),
          scroller: () => host(),
          contentBottom: (scroller) => scroller.scrollHeight,
          layout: signal(0),
        }),
    );
    const fixture = TestBed.createComponent(ProgressRailComponent);
    fixture.componentRef.setInput('progress', progress);
    fixture.detectChanges();
    const rail = fixture.nativeElement as HTMLElement;
    const fill = () => rail.style.getPropertyValue('--rail-fill');
    const scrollTo = (element: HTMLElement, top: number) => {
      element.scrollTop = top;
      element.dispatchEvent(new Event('scroll'));
    };
    const swapTo = (next: HTMLElement | undefined) => {
      host.set(next && elementScrollHost(next));
      fixture.detectChanges();
    };
    return { first, second, progress, fill, scrollTo, swapTo };
  }

  it('repaints on a scroll of its scroller', () => {
    const { first, fill, scrollTo } = setup();
    scrollTo(first, 450);
    expect(fill()).toBe('50');
  });

  it('stops listening to the old scroller and follows the new one', () => {
    const { first, second, fill, scrollTo, swapTo } = setup();
    swapTo(second);
    second.scrollTop = 225;

    scrollTo(first, 450);
    expect(fill()).toBe('0');

    second.dispatchEvent(new Event('scroll'));
    expect(fill()).toBe('25');
  });

  it('stops replacing the scrollbar once the scroller is gone', () => {
    const { progress, swapTo } = setup();
    expect(progress.replacesScrollbar()).toBe(true);

    swapTo(undefined);

    expect(progress.replacesScrollbar()).toBe(false);
    expect(progress.overflows()).toBe(false);
  });
});
