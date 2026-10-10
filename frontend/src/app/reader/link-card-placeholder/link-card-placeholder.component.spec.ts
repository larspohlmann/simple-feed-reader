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
  it('shows a silent image area, then announces that the preview is loading', () => {
    const element = mount();

    expect(element.querySelector('.image')!.getAttribute('aria-hidden')).toBe('true');
    const status = element.querySelector('[role="status"]')!;
    expect(status.textContent!.trim()).toBe('Loading preview…');
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
