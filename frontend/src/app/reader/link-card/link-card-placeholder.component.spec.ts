import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LinkCardPlaceholderComponent } from './link-card-placeholder.component';

function mount(): HTMLElement {
  TestBed.configureTestingModule({
    imports: [LinkCardPlaceholderComponent, provideTranslocoTesting()],
  });
  const fixture = TestBed.createComponent(LinkCardPlaceholderComponent);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('LinkCardPlaceholderComponent', () => {
  it('reads as busy, with its loading line as visible copy rather than a live region', () => {
    const element = mount();

    expect(element.getAttribute('aria-busy')).toBe('true');
    expect(element.querySelector('.image')!.getAttribute('aria-hidden')).toBe('true');
    expect(element.querySelector('[role="status"], [aria-live]')).toBeNull();
    expect(element.querySelector('.label')!.textContent!.trim()).toBe('Loading preview…');
  });

  it('says it in German', () => {
    TestBed.configureTestingModule({
      imports: [LinkCardPlaceholderComponent, provideTranslocoTesting()],
    });
    TestBed.inject(TranslocoService).setActiveLang('de');
    const fixture = TestBed.createComponent(LinkCardPlaceholderComponent);
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).textContent).toContain('Vorschau wird geladen…');
  });
});
