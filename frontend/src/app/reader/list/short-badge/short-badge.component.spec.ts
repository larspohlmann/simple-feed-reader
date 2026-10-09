import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ShortBadgeComponent, ShortBadgeForm } from './short-badge.component';

describe('ShortBadgeComponent', () => {
  function render(form?: ShortBadgeForm): HTMLElement {
    TestBed.configureTestingModule({ imports: [ShortBadgeComponent, provideTranslocoTesting()] });
    const fixture = TestBed.createComponent(ShortBadgeComponent);
    if (form) {
      fixture.componentRef.setInput('form', form);
    }
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('labels the image as a Short', () => {
    expect(render().textContent?.trim()).toBe('Short');
  });

  it('shows the word, not a glyph, by default', () => {
    expect(render().querySelector('app-icon')).toBeNull();
  });

  it('shows a glyph in its glyph form and keeps the word for screen readers', () => {
    const badge = render('glyph');

    expect(badge.querySelector('app-icon')).not.toBeNull();
    expect(badge.querySelector('.sr-only')?.textContent?.trim()).toBe('Short');
  });

  it('is not a focus stop', () => {
    const badge = render();

    expect(badge.hasAttribute('tabindex')).toBe(false);
    expect(badge.querySelector('a, button, [tabindex]')).toBeNull();
  });
});
