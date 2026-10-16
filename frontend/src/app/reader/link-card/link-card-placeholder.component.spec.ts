import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LinkCardPlaceholderComponent } from './link-card-placeholder.component';
import { PreviewProgress } from './preview-progress';

let sighted: jest.Mock;
let restoreObserver: (() => void) | null = null;

afterEach(() => {
  restoreObserver?.();
  restoreObserver = null;
});

function stubIntersectionObserver() {
  const callbacks: IntersectionObserverCallback[] = [];
  const observed: Element[] = [];
  const disconnect = jest.fn();
  const real = globalThis.IntersectionObserver;
  restoreObserver = () => (globalThis.IntersectionObserver = real);
  globalThis.IntersectionObserver = class {
    constructor(callback: IntersectionObserverCallback) {
      callbacks.push(callback);
    }
    readonly observe = (target: Element) => observed.push(target);
    readonly disconnect = disconnect;
  } as unknown as typeof IntersectionObserver;
  const report = (target: Element, isIntersecting: boolean) =>
    callbacks[0](
      [{ target, isIntersecting } as unknown as IntersectionObserverEntry],
      {} as IntersectionObserver,
    );
  return { observed, report, disconnect };
}

function mount({ loading = true, lang = 'en' } = {}): HTMLElement {
  sighted = jest.fn();
  TestBed.configureTestingModule({
    imports: [LinkCardPlaceholderComponent, provideTranslocoTesting()],
    providers: [
      {
        provide: PreviewProgress,
        useValue: { isLoading: (id: number) => loading && id === 7, sighted },
      },
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
    const element = mount({ loading: false });

    expect(element.hasAttribute('aria-busy')).toBe(false);
    expect(element.classList).not.toContain('loading');
    expect(element.querySelector('.label')!.textContent!.trim()).toBe('No preview yet');
  });

  it.each([
    [true, 'Vorschau wird geladen…'],
    [false, 'Noch keine Vorschau'],
  ])('says it in German (loading: %s)', (loading, text) => {
    expect(mount({ loading, lang: 'de' }).textContent).toContain(text);
  });

  describe('once it comes into view (#1646)', () => {
    it('asks for its preview at once, and only the first time', () => {
      const { observed, report, disconnect } = stubIntersectionObserver();
      const element = mount();
      expect(observed).toEqual([element]);

      report(element, false);
      expect(sighted).not.toHaveBeenCalled();
      report(element, true);

      expect(sighted.mock.calls).toEqual([[7]]);
      expect(disconnect).toHaveBeenCalled();
    });

    it('asks for one whose preview no longer loads too', () => {
      const { report } = stubIntersectionObserver();
      const element = mount({ loading: false });

      report(element, true);

      expect(sighted.mock.calls).toEqual([[7]]);
    });
  });
});
