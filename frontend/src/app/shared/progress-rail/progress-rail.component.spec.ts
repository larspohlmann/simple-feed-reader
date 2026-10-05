import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ProgressRailComponent } from './progress-rail.component';
import { ScrollProgressRail } from './scroll-progress-rail';

describe('ProgressRailComponent', () => {
  function mount(isWide: boolean) {
    const progress = TestBed.runInInjectionContext(
      () =>
        new ScrollProgressRail({
          isWide: signal(isWide),
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
