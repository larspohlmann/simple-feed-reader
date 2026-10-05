import { TestBed } from '@angular/core/testing';
import { ProgressRailComponent } from './progress-rail.component';

describe('ProgressRailComponent', () => {
  it('is decorative and renders a fill element', () => {
    const fixture = TestBed.createComponent(ProgressRailComponent);
    fixture.detectChanges();
    const host: HTMLElement = fixture.nativeElement;
    expect(host.getAttribute('aria-hidden')).toBe('true');
    expect(host.querySelector('i')).not.toBeNull();
    expect(host.classList).not.toContain('horizontal');
  });

  it('runs across when horizontal', () => {
    const fixture = TestBed.createComponent(ProgressRailComponent);
    fixture.componentRef.setInput('orientation', 'horizontal');
    fixture.detectChanges();
    expect((fixture.nativeElement as HTMLElement).classList).toContain('horizontal');
  });
});
