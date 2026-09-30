import { TestBed } from '@angular/core/testing';
import { CaughtUpIllustrationComponent } from './caught-up-illustration.component';

describe('CaughtUpIllustrationComponent', () => {
  function render(): SVGElement {
    const fixture = TestBed.createComponent(CaughtUpIllustrationComponent);
    fixture.detectChanges();
    return (fixture.nativeElement as HTMLElement).querySelector('svg')!;
  }

  it('is hidden from assistive technology, since the message beside it says it all', () => {
    const svg = render();

    expect(svg.getAttribute('aria-hidden')).toBe('true');
    expect(svg.getAttribute('focusable')).toBe('false');
  });

  it('draws the mascot next to its emptied bowl', () => {
    const svg = render();

    expect(svg.querySelector('.dot')).not.toBeNull();
    expect(svg.querySelector('.bowl')).not.toBeNull();
    expect(svg.querySelector('.chopsticks')).not.toBeNull();
  });
});
