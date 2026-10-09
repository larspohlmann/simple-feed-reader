import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ShortBadgeComponent } from './short-badge.component';

describe('ShortBadgeComponent', () => {
  function render(): HTMLElement {
    TestBed.configureTestingModule({ imports: [ShortBadgeComponent, provideTranslocoTesting()] });
    const fixture = TestBed.createComponent(ShortBadgeComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('labels the image as a Short', () => {
    expect(render().textContent?.trim()).toBe('Short');
  });

  it('is not a focus stop', () => {
    const badge = render();

    expect(badge.hasAttribute('tabindex')).toBe(false);
    expect(badge.querySelector('a, button, [tabindex]')).toBeNull();
  });
});
