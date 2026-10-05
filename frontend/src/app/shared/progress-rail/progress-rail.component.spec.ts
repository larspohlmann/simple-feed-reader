import { TestBed } from '@angular/core/testing';
import { ProgressRailComponent } from './progress-rail.component';

describe('ProgressRailComponent', () => {
  it('is decorative and renders the fill the rail styles size', () => {
    const fixture = TestBed.createComponent(ProgressRailComponent);
    fixture.detectChanges();
    const host: HTMLElement = fixture.nativeElement;
    expect(host.getAttribute('aria-hidden')).toBe('true');
    expect(host.querySelector('i')).not.toBeNull();
  });
});
