import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { ToastService } from '../../shared/toast/toast.service';
import { YourStreamSectionComponent } from './your-stream-section.component';
import { YourStreamSettingsService, YourStreamState } from './your-stream-settings.service';

type Fixture = ComponentFixture<YourStreamSectionComponent>;

const URL = '/api/me/your-stream';

function aState(over: Partial<YourStreamState> = {}): YourStreamState {
  return { enabled: true, precision: 'wide', defaultPrecision: 'wide', words: [], ...over };
}

describe('YourStreamSectionComponent', () => {
  let http: HttpTestingController;
  const toastStub = { show: jest.fn() };

  function create(): Fixture {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
        { provide: ToastService, useValue: toastStub },
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly: jest.fn() } },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(YourStreamSectionComponent);
    fixture.detectChanges();
    return fixture;
  }

  function mount(state: YourStreamState): Fixture {
    const fixture = create();
    http.expectOne(URL).flush(state);
    fixture.detectChanges();
    return fixture;
  }

  const queryIn = (fixture: Fixture, selector: string): HTMLElement | null =>
    fixture.nativeElement.querySelector(selector);
  const precisionRow = (fixture: Fixture): HTMLElement | null =>
    queryIn(fixture, '[data-testid="your-stream-precision"]');
  const click = (fixture: Fixture, selector: string): void =>
    (queryIn(fixture, selector) as HTMLButtonElement).click();
  const fillOf = (fixture: Fixture): string =>
    (queryIn(fixture, '[data-testid="your-stream-precision"] .fill') as HTMLElement).style
      .inlineSize;
  const streamPut = (): { body: unknown; flush: (body: object) => void } => {
    const request = http.expectOne(URL);
    expect(request.request.method).toBe('PUT');
    return { body: request.request.body, flush: (body) => request.flush(body) };
  };

  beforeEach(() => toastStub.show.mockReset());
  afterEach(() => http.verify());

  it('shows a spinner while the state loads', () => {
    const fixture = create();

    expect(queryIn(fixture, '[data-testid="your-stream-loading"]')).not.toBeNull();
    expect(queryIn(fixture, '[data-testid="your-stream-enabled"]')).toBeNull();
    http.expectOne(URL).flush(aState());
  });

  it('shows the error and no spinner when the load fails', () => {
    const fixture = create();

    http
      .expectOne(URL)
      .flush({ type: 'about:blank', title: 'boom' }, { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    expect(queryIn(fixture, '[data-testid="your-stream-loading"]')).toBeNull();
    expect(queryIn(fixture, 'app-error-banner')!.textContent).toContain('boom');
  });

  it('hides precision and word list while the stream is off', () => {
    const fixture = mount(aState({ enabled: false }));

    expect(queryIn(fixture, '[data-testid="your-stream-enabled"]')).not.toBeNull();
    expect(precisionRow(fixture)).toBeNull();
    expect(queryIn(fixture, '[data-testid="your-stream-words"]')).toBeNull();
  });

  it('saves the switch off and keeps the precision', () => {
    const fixture = mount(aState({ precision: 'balanced' }));
    const toggle = queryIn(
      fixture,
      '[data-testid="your-stream-enabled"] app-toggle input[type="checkbox"]',
    ) as HTMLInputElement;

    toggle.checked = false;
    toggle.dispatchEvent(new Event('change'));

    const put = streamPut();
    expect(put.body).toEqual({ enabled: false, precision: 'balanced' });
    put.flush(aState({ enabled: false }));
    fixture.detectChanges();
    expect(precisionRow(fixture)).toBeNull();
    expect(toastStub.show).toHaveBeenCalledTimes(1);
  });

  it('steps precision wider from wide to widest', () => {
    const fixture = mount(aState({ precision: 'wide' }));
    const saveSpy = jest.spyOn(TestBed.inject(YourStreamSettingsService), 'save');

    click(fixture, '[data-testid="your-stream-precision"] button.wider');

    expect(saveSpy).toHaveBeenCalledWith({ enabled: true, precision: 'widest' });
    streamPut().flush(aState({ precision: 'widest' }));
  });

  it('steps narrower from balanced to narrow', () => {
    const fixture = mount(aState({ precision: 'balanced' }));

    click(fixture, '[data-testid="your-stream-precision"] button.narrower');

    const put = streamPut();
    expect(put.body).toEqual({ enabled: true, precision: 'narrow' });
    put.flush(aState({ precision: 'narrow' }));
  });

  it('resets to the served default precision from the bar', () => {
    const fixture = mount(aState({ precision: 'narrowest', defaultPrecision: 'balanced' }));

    click(fixture, '[data-testid="your-stream-precision"] button.bar');

    const put = streamPut();
    expect(put.body).toEqual({ enabled: true, precision: 'balanced' });
    put.flush(aState({ precision: 'balanced', defaultPrecision: 'balanced' }));
  });

  it('fills a quarter of the bar at narrow', () => {
    expect(fillOf(mount(aState({ precision: 'narrow' })))).toBe('25%');
  });

  it('fills the whole bar at widest', () => {
    expect(fillOf(mount(aState({ precision: 'widest' })))).toBe('100%');
  });

  it('labels the precision stepper ends with words, not icons', () => {
    const fixture = mount(aState());

    const end = (selector: string): HTMLElement =>
      queryIn(fixture, `[data-testid="your-stream-precision"] ${selector}`) as HTMLElement;
    expect(end('button.narrower').textContent?.trim()).toBe('Narrow');
    expect(end('button.wider').textContent?.trim()).toBe('Wide');
    expect(end('button.narrower').getAttribute('aria-label')).toBe('Narrower');
    expect(end('button.wider').getAttribute('aria-label')).toBe('Wider');
  });

  it('snaps the bar back and shows the error when the save fails', () => {
    const fixture = mount(aState({ precision: 'balanced' }));

    click(fixture, '[data-testid="your-stream-precision"] button.wider');
    fixture.detectChanges();
    expect(fillOf(fixture)).toBe('75%');
    http
      .expectOne(URL)
      .flush({ type: 'about:blank', title: 'boom' }, { status: 500, statusText: 'Server Error' });
    fixture.detectChanges();

    expect(fillOf(fixture)).toBe('50%');
    expect(queryIn(fixture, 'app-error-banner')!.textContent).toContain('boom');
    expect(toastStub.show).not.toHaveBeenCalled();
  });

  it('lists words per language, strongest first as served', () => {
    const fixture = mount(
      aState({
        words: [
          {
            language: 'de',
            words: [
              { word: 'Kernkraft', weight: 0.85 },
              { word: 'Netz', weight: 0.31 },
            ],
          },
          { language: 'fr', words: [{ word: 'énergie', weight: 0.4 }] },
        ],
      }),
    );

    const list = queryIn(fixture, '[data-testid="your-stream-words"]') as HTMLElement;
    const text = list.textContent!.replace(/\s+/g, ' ');
    expect(text.indexOf('German')).toBeLessThan(text.indexOf('Kernkraft'));
    expect(text.indexOf('Kernkraft')).toBeLessThan(text.indexOf('Netz'));
    expect(text.indexOf('Netz')).toBeLessThan(text.indexOf('French'));
    const rows = Array.from(list.querySelectorAll('.stream-word')).map((row) =>
      Array.from(row.children).map((cell) => cell.textContent),
    );
    expect(rows).toEqual([
      ['Kernkraft', '0.9'],
      ['Netz', '0.3'],
      ['énergie', '0.4'],
    ]);
  });

  it('shows the empty state when the list has no words', () => {
    const fixture = mount(aState({ words: [] }));

    expect(queryIn(fixture, '[data-testid="your-stream-words"]')!.textContent).toContain(
      'No word list yet. Favourite, keep, like or share articles to fill your stream.',
    );
    expect(queryIn(fixture, '[data-testid="your-stream-words-clip"]')).toBeNull();
  });

  it('labels the cut-form group as feeds without a language', () => {
    const fixture = mount(
      aState({ words: [{ language: null, words: [{ word: 'Bundesliga', weight: 0.5 }] }] }),
    );

    expect(queryIn(fixture, '[data-testid="your-stream-words"]')!.textContent).toContain(
      'Feeds without a language',
    );
  });

  describe('the collapsible word list', () => {
    const GERMAN = { language: 'de', words: [{ word: 'Kernkraft', weight: 0.85 }] };
    const LINE_PX = 20;
    let heights: { body: number };

    beforeEach(() => {
      heights = { body: LINE_PX * 9 };
      jest.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockImplementation(function (
        this: HTMLElement,
      ) {
        if (this.classList.contains('stream-word-body')) return heights.body;
        return this.classList.contains('stream-word-limit') ? LINE_PX * 5 : 0;
      });
    });

    afterEach(() => jest.restoreAllMocks());

    const mountList = (): Fixture => {
      const fixture = mount(aState({ words: [GERMAN] }));
      fixture.detectChanges();
      return fixture;
    };
    const toggle = (fixture: Fixture): HTMLButtonElement | null =>
      queryIn(fixture, '[data-testid="your-stream-words-toggle"]') as HTMLButtonElement | null;
    const clip = (fixture: Fixture): HTMLElement =>
      queryIn(fixture, '[data-testid="your-stream-words-clip"]') as HTMLElement;

    it('starts collapsed with a More button when the list is longer than five lines', () => {
      const fixture = mountList();

      expect(toggle(fixture)!.textContent!.trim()).toBe('Show more');
      expect(toggle(fixture)!.getAttribute('aria-expanded')).toBe('false');
      expect(toggle(fixture)!.getAttribute('aria-controls')).toBe(clip(fixture).id);
      expect(clip(fixture).id).toMatch(/^your-stream-word-clip-/);
      expect(clip(fixture).classList).not.toContain('expanded');
    });

    it('expands and collapses on the button', () => {
      const fixture = mountList();

      toggle(fixture)!.click();
      fixture.detectChanges();
      expect(clip(fixture).classList).toContain('expanded');
      expect(toggle(fixture)!.textContent!.trim()).toBe('Show less');

      toggle(fixture)!.click();
      fixture.detectChanges();
      expect(clip(fixture).classList).not.toContain('expanded');
    });

    it('shows no button for a list of exactly five lines', () => {
      heights.body = LINE_PX * 5;

      expect(toggle(mountList())).toBeNull();
    });
  });
});
