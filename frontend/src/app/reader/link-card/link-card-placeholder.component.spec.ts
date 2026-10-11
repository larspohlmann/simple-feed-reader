import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LinkCardPlaceholderComponent } from './link-card-placeholder.component';
import { PreviewProgress } from './preview-progress';

function mount(loading = true, lang = 'en'): HTMLElement {
  TestBed.configureTestingModule({
    imports: [LinkCardPlaceholderComponent, provideTranslocoTesting()],
    providers: [
      { provide: PreviewProgress, useValue: { isLoading: (id: number) => loading && id === 7 } },
    ],
  });
  TestBed.inject(TranslocoService).setActiveLang(lang);
  const fixture = TestBed.createComponent(LinkCardPlaceholderComponent);
  fixture.componentRef.setInput('entryId', 7);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('LinkCardPlaceholderComponent', () => {
  it('reads as busy, with its loading line as visible copy rather than a live region', () => {
    const element = mount();

    expect(element.getAttribute('aria-busy')).toBe('true');
    expect(element.classList).toContain('loading');
    expect(element.querySelector('.image')!.getAttribute('aria-hidden')).toBe('true');
    expect(element.querySelector('[role="status"], [aria-live]')).toBeNull();
    expect(element.querySelector('.label')!.textContent!.trim()).toBe('Loading preview…');
  });

  it('stops claiming progress once nothing works on the preview any more (#1516)', () => {
    const element = mount(false);

    expect(element.hasAttribute('aria-busy')).toBe(false);
    expect(element.classList).not.toContain('loading');
    expect(element.querySelector('.label')!.textContent!.trim()).toBe('No preview yet');
  });

  it('says it in German', () => {
    expect(mount(true, 'de').textContent).toContain('Vorschau wird geladen…');
    TestBed.resetTestingModule();
    expect(mount(false, 'de').textContent).toContain('Noch keine Vorschau');
  });
});
