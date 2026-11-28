import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import {
  AiAvailabilityService,
  NO_RECOMMENDATION_CAPABILITIES,
  RecommendationCapabilities,
} from '../../core/ai-availability.service';
import { API_BASE_URL } from '../../core/api';
import {
  EVERY_RECOMMENDATION_CAPABILITY,
  SCORING_RECOMMENDATION_CAPABILITIES,
} from '../../../testing/recommendation-capabilities';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { CONFIRMATION_DURATION_MS, ToastService } from '../../shared/toast/toast.service';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import { RecommendationSettingsCardComponent } from './recommendation-settings-card.component';
import { RecommendationSettingsState } from './recommendation-settings.service';

describe('RecommendationSettingsCardComponent', () => {
  let http: HttpTestingController;
  const dialogStub = { open: jest.fn() };
  const toastStub = { show: jest.fn() };

  const STATE: RecommendationSettingsState = {
    guidancePrompt: null,
    defaultGuidancePrompt: 'Prefer long-form articles on the topics you already read.',
    fixedPrompt: {
      role: 'You are the recommendation engine for a feed reader.',
      outputContract: 'Return at most 20 picks as a JSON array of entry ids.',
    },
    expertDefaults: {
      guidancePrompt: null,
      favoritesCap: 40,
      candidatePoolSize: 500,
      picksLimit: 50,
      batchSize: 'medium',
      contextWindow: null,
    },
    expertBounds: {
      favoritesCap: { min: 0, max: 500 },
      candidatePoolSize: { min: 10, max: 5000 },
      picksLimit: { min: 1, max: 500 },
      contextWindow: { min: 4096, max: 2097152 },
    },
    favoritesCap: 50,
    candidatePoolSize: 400,
    picksLimit: 20,
    batchSize: 'medium',
    contextWindow: 128000,
    contextWindowOverride: null,
    contextWindowSource: 'provider',
    debugEnabled: false,
    autoGenerateIntervalHours: null,
    lookbackDays: 2,
    workerAlive: true,
    showScoreAndReasons: false,
    forYouStream: {
      enabled: true,
      precision: 'balanced',
      defaultPrecision: 'wide',
      phrases: [],
    },
  };

  function guidanceInfo(fixture: ComponentFixture<RecommendationSettingsCardComponent>): string {
    return (
      fixture.nativeElement.querySelector('[data-testid="guidance-info"]') as HTMLElement
    ).textContent!.trim();
  }

  function guidanceResetLabel(
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): string {
    return (
      fixture.nativeElement.querySelector('[data-testid="guidance-reset"]') as HTMLElement
    ).textContent!.trim();
  }

  function mount(
    initial: RecommendationSettingsState = STATE,
    capabilities: RecommendationCapabilities = EVERY_RECOMMENDATION_CAPABILITY,
  ): ComponentFixture<RecommendationSettingsCardComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
        { provide: Dialog, useValue: dialogStub },
        { provide: ToastService, useValue: toastStub },
      ],
    });
    TestBed.inject(AiAvailabilityService).apply({ ready: true, model: 'gpt-4o', capabilities });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(RecommendationSettingsCardComponent);
    fixture.detectChanges();
    http.expectOne('/api/me/ai/recommendations').flush(initial);
    fixture.detectChanges();
    return fixture;
  }

  const banner = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLElement | null => fixture.nativeElement.querySelector('app-error-banner');

  const cadenceSelect = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLSelectElement =>
    fixture.nativeElement.querySelector('select[data-testid="auto-generate"]');

  const lookbackSelect = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLSelectElement =>
    fixture.nativeElement.querySelector('select[data-testid="lookback-days"]');

  const showScoreAndReasonsToggle = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLInputElement =>
    fixture.nativeElement.querySelector(
      '[data-testid="show-score-and-reasons"] app-toggle input[type="checkbox"]',
    );

  const batchSizeSelect = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLSelectElement | null =>
    fixture.nativeElement.querySelector('select[data-testid="batch-size"]');

  const contextWindowInput = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLInputElement | null =>
    fixture.nativeElement.querySelector('input[data-testid="context-window"]');

  const debugToggle = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLInputElement => fixture.nativeElement.querySelector('#rec-debug-toggle');

  const picksInput = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLInputElement => fixture.nativeElement.querySelector('input[min="1"][max="500"]');

  const saveButton = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLButtonElement =>
    fixture.nativeElement.querySelector(
      'app-settings-save-bar app-button[variant="primary"] button',
    );

  const resetButton = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLButtonElement =>
    fixture.nativeElement.querySelector('app-settings-save-bar app-button[variant="ghost"] button');

  const factoryDefaultsButton = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLButtonElement =>
    fixture.nativeElement.querySelector('[data-testid="factory-defaults-reset"] button');

  beforeEach(() => {
    dialogStub.open.mockReset();
    toastStub.show.mockReset();
  });

  afterEach(() => http.verify());

  it('renders the loaded values into the fields', () => {
    const fixture = mount();

    expect(fixture.componentInstance.favoritesCap()).toBe(50);
    expect(fixture.componentInstance.picksLimit()).toBe(20);
    expect(fixture.componentInstance.batchSize()).toBe('medium');
    expect(fixture.componentInstance.contextWindow()).toBeNull();
    expect(fixture.componentInstance.guidance()).toBe('');
    expect(fixture.componentInstance.debugEnabled()).toBe(false);
    expect(fixture.componentInstance.showScoreAndReasons()).toBe(false);
  });

  it('shows the fixed prompt, read-only, inside a disclosure', () => {
    const fixture = mount();

    const pre = fixture.nativeElement.querySelector('details pre.fixed') as HTMLElement;
    expect(pre.textContent).toContain('You are the recommendation engine for a feed reader.');
    expect(pre.textContent).toContain('Return at most 20 picks as a JSON array of entry ids.');
  });

  it('renders the three numeric tuning fields and the batch-size select inside the expert drill-in', () => {
    const fixture = mount();

    const grid = fixture.nativeElement.querySelector('details .expert-grid') as HTMLElement;
    const labels = Array.from(grid.querySelectorAll('label')).map((element) =>
      element.textContent?.trim(),
    );
    expect(labels).toEqual(
      expect.arrayContaining([
        expect.stringContaining('Favorites in history'),
        expect.stringContaining('Maximum articles'),
        expect.stringContaining('Maximum picks'),
        expect.stringContaining('Batch size'),
      ]),
    );
  });

  it("shows each expert numeric field's allowed range", () => {
    const fixture = mount();
    const rangeElements = fixture.nativeElement.querySelectorAll(
      '.numeric-range',
    ) as NodeListOf<HTMLElement>;
    const ranges = Array.from(rangeElements).map((range) => range.textContent?.trim());

    expect(ranges).toEqual(
      expect.arrayContaining(['0–500', '10–5,000', '1–500', '4,096–2,097,152']),
    );
    expect(ranges.filter((range) => range === '0–500')).toHaveLength(1);
  });

  describe("rendering from the engine's capabilities", () => {
    it('offers the score-and-reasons switch, the batch size and the context window to an engine that reads them all', () => {
      const fixture = mount();

      expect(showScoreAndReasonsToggle(fixture)).not.toBeNull();
      expect(batchSizeSelect(fixture)).not.toBeNull();
      expect(contextWindowInput(fixture)).not.toBeNull();
    });

    it('offers no tuning to an engine without it, and keeps the shared settings', () => {
      const fixture = mount(STATE, NO_RECOMMENDATION_CAPABILITIES);

      expect(batchSizeSelect(fixture)).toBeNull();
      expect(contextWindowInput(fixture)).toBeNull();
      expect(picksInput(fixture)).not.toBeNull();
      expect(cadenceSelect(fixture)).not.toBeNull();
      expect(debugToggle(fixture)).not.toBeNull();
    });

    it('offers each tuning control by its own field', () => {
      const fixture = mount(STATE, {
        ...NO_RECOMMENDATION_CAPABILITIES,
        tuningFields: ['contextWindow'],
      });

      expect(contextWindowInput(fixture)).not.toBeNull();
      expect(batchSizeSelect(fixture)).toBeNull();
    });

    it('offers the score-and-reasons switch to an engine that writes no reasons, for its score', () => {
      const fixture = mount(STATE, {
        ...NO_RECOMMENDATION_CAPABILITIES,
        tuningFields: ['batchConcurrency'],
      });

      expect(showScoreAndReasonsToggle(fixture)).not.toBeNull();
    });
  });

  describe("the prompt capability's read-only pieces", () => {
    it('shows no fixed prompt or guidance default to an engine that sends no prompt', () => {
      const fixture = mount(STATE, SCORING_RECOMMENDATION_CAPABILITIES);
      const guidance = fixture.nativeElement.querySelector('textarea') as HTMLTextAreaElement;

      expect(fixture.nativeElement.querySelector('details pre.fixed')).toBeNull();
      expect(guidance.placeholder).toBe('');
    });

    it('explains the guidance against the fixed prompt to an engine that sends one', () => {
      const fixture = mount();

      expect(guidanceInfo(fixture)).toContain('fixed prompt');
    });

    it('explains the guidance against the reading history to an engine that sends no prompt', () => {
      const fixture = mount(STATE, {
        ...NO_RECOMMENDATION_CAPABILITIES,
        tuningFields: ['batchConcurrency'],
      });

      expect(guidanceInfo(fixture)).toContain('reading history');
      expect(guidanceInfo(fixture)).not.toContain('fixed prompt');
    });

    it('offers to reset the guidance to the default prompt of an engine that has one', () => {
      const fixture = mount();

      expect(guidanceResetLabel(fixture)).toBe('Reset the prompt to default');
    });

    it('offers only to clear the guidance of an engine without a default prompt', () => {
      const fixture = mount(STATE, {
        ...NO_RECOMMENDATION_CAPABILITIES,
        tuningFields: ['batchConcurrency'],
      });

      expect(guidanceResetLabel(fixture)).toBe('Clear the guidance');
    });
  });

  describe('the show-score-and-reasons switch (instant)', () => {
    it('persists immediately with showScoreAndReasons in the PUT body', () => {
      const fixture = mount();

      const toggle = showScoreAndReasonsToggle(fixture);
      toggle.checked = true;
      toggle.dispatchEvent(new Event('change'));

      const request = http.expectOne('/api/me/ai/recommendations');
      expect(request.request.method).toBe('PUT');
      expect(request.request.body.showScoreAndReasons).toBe(true);
      request.flush({ ...STATE, showScoreAndReasons: true });

      expect(fixture.componentInstance.showScoreAndReasons()).toBe(true);
    });

    it('leaves the dirty flag untouched — it is not a typed edit', () => {
      const fixture = mount();

      fixture.componentInstance.onShowScoreAndReasons(true);
      http.expectOne('/api/me/ai/recommendations').flush({ ...STATE, showScoreAndReasons: true });

      expect(fixture.componentInstance.svc.dirty()).toBe(false);
    });
  });

  describe('the cadence and look-back selects (instant)', () => {
    it('persists the chosen cadence immediately', () => {
      const fixture = mount({ ...STATE, autoGenerateIntervalHours: null });

      const dropdown = cadenceSelect(fixture);
      dropdown.value = '12';
      dropdown.dispatchEvent(new Event('change'));

      const request = http.expectOne('/api/me/ai/recommendations');
      expect(request.request.method).toBe('PUT');
      expect(request.request.body.autoGenerateIntervalHours).toBe(12);
      request.flush({ ...STATE, autoGenerateIntervalHours: 12 });
    });

    it('persists the chosen look-back window immediately', () => {
      const fixture = mount();

      const dropdown = lookbackSelect(fixture);
      dropdown.value = '7';
      dropdown.dispatchEvent(new Event('change'));

      const request = http.expectOne('/api/me/ai/recommendations');
      expect(request.request.body.lookbackDays).toBe(7);
      request.flush({ ...STATE, lookbackDays: 7 });
    });

    it('renders the look-back select outside the expert drill-in', () => {
      const fixture = mount({ ...STATE, lookbackDays: 5 });

      const select = lookbackSelect(fixture);
      expect(select.closest('app-disclosure')).toBeNull();
      expect(fixture.componentInstance.lookbackDays()).toBe(5);
    });
  });

  describe('the debug switch (instant)', () => {
    it('persists debugEnabled immediately, without a typed save', () => {
      const fixture = mount();

      const toggle = debugToggle(fixture);
      toggle.checked = true;
      toggle.dispatchEvent(new Event('change'));

      const request = http.expectOne('/api/me/ai/recommendations');
      expect(request.request.body.debugEnabled).toBe(true);
      request.flush({ ...STATE, debugEnabled: true });

      expect(fixture.componentInstance.svc.dirty()).toBe(false);
    });
  });

  describe('typed fields and the save bar', () => {
    it('a typed cap edit sets dirty but does not save until Save is pressed', () => {
      const fixture = mount();

      const input = picksInput(fixture);
      input.value = '30';
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();

      expect(fixture.componentInstance.picksLimit()).toBe(30);
      expect(fixture.componentInstance.svc.dirty()).toBe(true);
      http.expectNone('/api/me/ai/recommendations');

      saveButton(fixture).click();

      const request = http.expectOne('/api/me/ai/recommendations');
      expect(request.request.method).toBe('PUT');
      expect(request.request.body).toEqual(expect.objectContaining({ picksLimit: 30 }));
      request.flush({ ...STATE, picksLimit: 30 });

      expect(fixture.componentInstance.svc.dirty()).toBe(false);
    });

    it('leaves the last value in place when a capped numeric field is cleared', () => {
      const fixture = mount();

      const input = picksInput(fixture);
      input.value = '';
      input.dispatchEvent(new Event('input'));

      // +'' === 0, which is below picksLimit's own min="1" -- a naive coercion
      // would silently arm a save that 422s.
      expect(fixture.componentInstance.picksLimit()).toBe(20);
      expect(fixture.componentInstance.svc.dirty()).toBe(false);
    });

    it('sends the batch size and context window override the user chose', () => {
      const fixture = mount();

      fixture.componentInstance.onBatchSizeChange({
        target: { value: 'large' },
      } as unknown as Event);
      fixture.componentInstance.onContextWindowInput({
        target: { value: '64000' },
      } as unknown as Event);
      fixture.detectChanges();

      saveButton(fixture).click();

      const request = http.expectOne('/api/me/ai/recommendations');
      expect(request.request.body).toEqual(
        expect.objectContaining({ batchSize: 'large', contextWindow: 64000 }),
      );
      request.flush({ ...STATE, batchSize: 'large' });
    });

    it('sends guidancePrompt: null after a reset to default', () => {
      const fixture = mount({ ...STATE, guidancePrompt: 'Focus on space exploration.' });
      expect(fixture.componentInstance.guidance()).toBe('Focus on space exploration.');

      fixture.componentInstance.resetGuidance();
      fixture.detectChanges();
      expect(fixture.componentInstance.guidance()).toBe('');

      saveButton(fixture).click();

      const request = http.expectOne('/api/me/ai/recommendations');
      expect(request.request.body).toEqual(expect.objectContaining({ guidancePrompt: null }));
      request.flush({ ...STATE, guidancePrompt: null });
    });

    it('reseeds every expert field as a pending factory-default draft', () => {
      const fixture = mount({ ...STATE, guidancePrompt: 'Focus on space exploration.' });

      factoryDefaultsButton(fixture).click();

      expect(fixture.componentInstance.guidance()).toBe('');
      expect(fixture.componentInstance.favoritesCap()).toBe(40);
      expect(fixture.componentInstance.candidatePoolSize()).toBe(500);
      expect(fixture.componentInstance.picksLimit()).toBe(50);
      expect(fixture.componentInstance.batchSize()).toBe('medium');
      expect(fixture.componentInstance.contextWindow()).toBeNull();
      expect(fixture.componentInstance.svc.draft()).toEqual(STATE.expertDefaults);
      http.expectNone('/api/me/ai/recommendations');
    });

    it('saves every factory default after the user resets the expert draft', () => {
      const fixture = mount({ ...STATE, guidancePrompt: 'Focus on space exploration.' });

      factoryDefaultsButton(fixture).click();
      fixture.detectChanges();
      saveButton(fixture).click();

      const request = http.expectOne('/api/me/ai/recommendations');
      expect(request.request.body).toEqual(expect.objectContaining(STATE.expertDefaults));
      request.flush({ ...STATE, ...STATE.expertDefaults });
    });

    it('lets the save bar discard a factory-default draft before a save', () => {
      const fixture = mount({ ...STATE, guidancePrompt: 'Focus on space exploration.' });

      factoryDefaultsButton(fixture).click();
      fixture.detectChanges();
      resetButton(fixture).click();
      fixture.detectChanges();

      expect(fixture.componentInstance.guidance()).toBe('Focus on space exploration.');
      expect(fixture.componentInstance.favoritesCap()).toBe(50);
      expect(fixture.componentInstance.svc.dirty()).toBe(false);
      http.expectNone('/api/me/ai/recommendations');
    });

    it('Reset discards the pending typed edit and reseeds the input', () => {
      const fixture = mount();

      const input = picksInput(fixture);
      input.value = '30';
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      expect(fixture.componentInstance.svc.dirty()).toBe(true);

      resetButton(fixture).click();
      fixture.detectChanges();

      expect(fixture.componentInstance.svc.dirty()).toBe(false);
      expect(fixture.componentInstance.picksLimit()).toBe(20);
      expect(picksInput(fixture).value).toBe('20');
      http.expectNone('/api/me/ai/recommendations');
    });

    it('shows the backend field error and marks its input invalid when the save is rejected', () => {
      const fixture = mount();

      const input = picksInput(fixture);
      input.value = '500';
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      saveButton(fixture).click();

      http.expectOne('/api/me/ai/recommendations').flush(
        {
          type: 'validation_error',
          title: 'Validation failed',
          status: 422,
          detail: 'One or more fields are invalid.',
          errors: { picksLimit: ['This value should be between 1 and 500.'] },
        },
        { status: 422, statusText: 'Unprocessable Content' },
      );
      fixture.detectChanges();

      expect(banner(fixture)?.textContent).toContain('This value should be between 1 and 500.');
      expect(picksInput(fixture).getAttribute('aria-invalid')).toBe('true');
      expect(fixture.nativeElement.textContent).toContain(
        'This value should be between 1 and 500.',
      );
    });

    it('rejects an out-of-range value in the browser without sending it', () => {
      const fixture = mount();

      const input = picksInput(fixture);
      input.value = '9999';
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      saveButton(fixture).click();
      fixture.detectChanges();

      expect(http.match('/api/me/ai/recommendations')).toHaveLength(0);
      expect(input.getAttribute('aria-invalid')).toBe('true');
      expect(fixture.nativeElement.textContent).toContain('1–500');
    });

    it('keeps an invalid cap error when the user clears the field without correcting it', () => {
      const fixture = mount();

      const input = picksInput(fixture);
      input.value = '9999';
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      saveButton(fixture).click();
      fixture.detectChanges();

      input.value = '';
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();

      expect(picksInput(fixture).getAttribute('aria-invalid')).toBe('true');
    });

    it('clears a backend field error when the user corrects that field', () => {
      const fixture = mount();

      const input = picksInput(fixture);
      input.value = '500';
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      saveButton(fixture).click();
      http.expectOne('/api/me/ai/recommendations').flush(
        {
          type: 'validation_error',
          title: 'Validation failed',
          status: 422,
          errors: { picksLimit: ['This value should be between 1 and 500.'] },
        },
        { status: 422, statusText: 'Unprocessable Content' },
      );
      fixture.detectChanges();

      const correctedInput = picksInput(fixture);
      correctedInput.value = '50';
      correctedInput.dispatchEvent(new Event('input'));
      fixture.detectChanges();

      expect(picksInput(fixture).getAttribute('aria-invalid')).toBe('false');
      expect(fixture.nativeElement.textContent).not.toContain(
        'This value should be between 1 and 500.',
      );
    });

    it('clears an invalid state when factory defaults replace an invalid draft', () => {
      const fixture = mount();

      const input = picksInput(fixture);
      input.value = '9999';
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      saveButton(fixture).click();
      fixture.detectChanges();

      factoryDefaultsButton(fixture).click();
      fixture.detectChanges();

      expect(picksInput(fixture).value).toBe('50');
      expect(picksInput(fixture).getAttribute('aria-invalid')).toBe('false');
    });
  });

  describe('the success toast', () => {
    it('fires once on an actual persist success, not on the click', () => {
      const fixture = mount();

      fixture.componentInstance.onShowScoreAndReasons(true);
      // No toast yet: the PUT is in flight.
      expect(toastStub.show).not.toHaveBeenCalled();

      http.expectOne('/api/me/ai/recommendations').flush({ ...STATE, showScoreAndReasons: true });
      fixture.detectChanges();

      expect(toastStub.show).toHaveBeenCalledTimes(1);
      expect(toastStub.show).toHaveBeenCalledWith({
        message: 'Saved.',
        durationMs: CONFIRMATION_DURATION_MS,
      });
    });

    it('stays silent when a save is rejected', () => {
      const fixture = mount();

      const input = picksInput(fixture);
      input.value = '50';
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();
      saveButton(fixture).click();

      http
        .expectOne('/api/me/ai/recommendations')
        .flush(
          { type: 'validation_error', title: 'Validation failed', status: 422 },
          { status: 422, statusText: 'Unprocessable Content' },
        );
      fixture.detectChanges();

      expect(toastStub.show).not.toHaveBeenCalled();
    });
  });

  it('reports the effective context window source', () => {
    const providerFixture = mount({ ...STATE, contextWindowSource: 'provider' });
    expect(providerFixture.nativeElement.textContent).toContain('Reported by your provider');

    const fallbackFixture = mount({ ...STATE, contextWindowSource: 'fallback' });
    expect(fallbackFixture.nativeElement.textContent).toContain('Built-in default');
  });

  it('hides the cron help note while a worker is alive', () => {
    const fixture = mount({ ...STATE, workerAlive: true });
    expect(fixture.nativeElement.querySelector('.cron-example')).toBeNull();
  });

  it('shows the cron help note when no worker is alive', () => {
    const fixture = mount({ ...STATE, workerAlive: false });
    expect(fixture.nativeElement.querySelector('.cron-example')).not.toBeNull();
  });

  it('gives every expert cap field an info tip', () => {
    const fixture = mount();

    const grid = fixture.nativeElement.querySelector('.expert-grid') as HTMLElement;
    const triggers = Array.from(
      grid.querySelectorAll('app-info-tip button.trigger'),
    ) as HTMLButtonElement[];
    expect(triggers.map((element) => element.getAttribute('aria-label'))).toEqual([
      'Favorites in history',
      'Maximum articles',
      'Maximum picks',
      'Batch size',
    ]);
  });

  it('no longer shows the profile or the kept and viewed caps', () => {
    const fixture = mount();

    expect(
      fixture.nativeElement.querySelector('[data-testid="recommendation-profile"]'),
    ).toBeNull();
    expect(fixture.nativeElement.textContent).not.toContain('Kept in history');
  });

  describe('clearing recommendations', () => {
    it('does nothing until the confirm dialog resolves true', () => {
      const fixture = mount();
      dialogStub.open.mockReturnValue({ closed: of(false) });

      fixture.componentInstance.confirmPurge();

      http.expectNone((request) => request.method === 'DELETE');
    });

    it('purges, refreshes the recommendation status and shows the confirmation line', () => {
      const fixture = mount();
      const reloadCounts = jest.spyOn(TestBed.inject(SubscriptionsStore), 'reloadCountsQuietly');
      dialogStub.open.mockReturnValue({ closed: of(true) });

      fixture.componentInstance.confirmPurge();

      const purgeRequest = http.expectOne('/api/recommendations/runs');
      expect(purgeRequest.request.method).toBe('DELETE');
      purgeRequest.flush({
        status: 'none',
        batchesTotal: null,
        batchesDone: 0,
        error: null,
        background: false,
        streamedChars: 0,
        forYou: { totalCount: 0, generatedAt: '2026-08-08T09:00:00Z', newestRunId: null },
      });

      const statusRequest = http.expectOne('/api/recommendations/runs/current');
      expect(statusRequest.request.method).toBe('GET');
      statusRequest.flush({
        status: 'none',
        batchesTotal: null,
        batchesDone: 0,
        error: null,
        background: false,
        streamedChars: 0,
        forYou: { totalCount: 0, generatedAt: null, newestRunId: null },
      });
      fixture.detectChanges();

      expect(reloadCounts).toHaveBeenCalledTimes(1);
      expect(fixture.nativeElement.textContent).toContain('Recommendations cleared.');
    });

    it('shows the 409 detail when a run is active instead of a generic error', () => {
      const fixture = mount();
      dialogStub.open.mockReturnValue({ closed: of(true) });

      fixture.componentInstance.confirmPurge();

      http.expectOne('/api/recommendations/runs').flush(
        {
          type: 'recommendation_run_active',
          title: 'A recommendation run is still active',
          status: 409,
          detail: 'Wait for the current run to finish, then try again.',
        },
        { status: 409, statusText: 'Conflict' },
      );
      fixture.detectChanges();

      expect(fixture.nativeElement.textContent).toContain(
        'Wait for the current run to finish, then try again.',
      );
      http.expectNone('/api/recommendations/runs/current');
    });

    it('passes the purge copy to the confirm dialog', () => {
      const fixture = mount();
      dialogStub.open.mockReturnValue({ closed: of(false) });

      fixture.componentInstance.confirmPurge();

      const [, config] = dialogStub.open.mock.calls.at(-1) as [
        unknown,
        { data: { title: string; confirmLabel: string; danger?: boolean } },
      ];
      expect(config.data.title).toBe('Clear all recommendations?');
      expect(config.data.confirmLabel).toBe('Clear');
      expect(config.data.danger).toBe(true);
    });
  });

  describe('the For you stream group', () => {
    type Fixture = ComponentFixture<RecommendationSettingsCardComponent>;
    type Stream = RecommendationSettingsState['forYouStream'];

    const streamState = (over: Partial<Stream> = {}): RecommendationSettingsState => ({
      ...STATE,
      forYouStream: { ...STATE.forYouStream, ...over },
    });
    const queryIn = (fixture: Fixture, selector: string): HTMLElement | null =>
      fixture.nativeElement.querySelector(selector);
    const precisionRow = (fixture: Fixture): HTMLElement | null =>
      queryIn(fixture, '[data-testid="for-you-stream-precision"]');
    const click = (fixture: Fixture, selector: string): void =>
      (queryIn(fixture, selector) as HTMLButtonElement).click();
    const STREAM_URL = '/api/me/ai/recommendations/for-you-stream';
    const streamPut = (): { body: unknown; flush: (body: object) => void } => {
      const request = http.expectOne(STREAM_URL);
      expect(request.request.method).toBe('PUT');
      return { body: request.request.body, flush: (body) => request.flush(body) };
    };

    it('hides precision and word list while the stream is off', () => {
      const fixture = mount(streamState({ enabled: false }));

      expect(queryIn(fixture, '[data-testid="for-you-stream-enabled"]')).not.toBeNull();
      expect(precisionRow(fixture)).toBeNull();
      expect(queryIn(fixture, '[data-testid="for-you-stream-phrases"]')).toBeNull();
    });

    it('saves the switch off through saveForYouStream and keeps the precision', () => {
      const fixture = mount(streamState({ precision: 'balanced' }));
      const toggle = queryIn(
        fixture,
        '[data-testid="for-you-stream-enabled"] app-toggle input[type="checkbox"]',
      ) as HTMLInputElement;

      toggle.checked = false;
      toggle.dispatchEvent(new Event('change'));

      const put = streamPut();
      expect(put.body).toEqual({ enabled: false, precision: 'balanced' });
      put.flush(streamState({ enabled: false }));
      fixture.detectChanges();
      expect(precisionRow(fixture)).toBeNull();
    });

    it('steps wider from balanced to wide and narrower to narrow', () => {
      const fixture = mount(streamState({ precision: 'balanced' }));

      click(fixture, '[data-testid="for-you-stream-precision"] button.wider');
      const wider = streamPut();
      expect(wider.body).toEqual({ enabled: true, precision: 'wide' });
      wider.flush(streamState({ precision: 'wide' }));
      fixture.detectChanges();

      click(fixture, '[data-testid="for-you-stream-precision"] button.narrower');
      click(fixture, '[data-testid="for-you-stream-precision"] button.narrower');
      const [, last] = http.match(STREAM_URL);
      expect(last.request.body).toEqual({ enabled: true, precision: 'narrow' });
      last.flush(streamState({ precision: 'narrow' }));
    });

    it('resets to the default precision from the bar', () => {
      const fixture = mount(streamState({ precision: 'narrowest' }));

      click(fixture, '[data-testid="for-you-stream-precision"] button.bar');

      const put = streamPut();
      expect(put.body).toEqual({ enabled: true, precision: 'wide' });
      put.flush(streamState({ precision: 'wide' }));
    });

    it('fills the bar towards wide', () => {
      const fill = (fixture: Fixture): string =>
        (queryIn(fixture, '[data-testid="for-you-stream-precision"] .fill') as HTMLElement).style
          .inlineSize;

      expect(fill(mount(streamState({ precision: 'narrow' })))).toBe('25%');
      expect(fill(mount(streamState({ precision: 'widest' })))).toBe('100%');
    });

    it('lists phrases per language, strongest first as served', () => {
      const fixture = mount(
        streamState({
          phrases: [
            {
              language: 'de',
              phrases: [
                { phrase: 'Kernkraft', weight: 0.85 },
                { phrase: 'Netz', weight: 0.31 },
              ],
            },
            { language: 'fr', phrases: [{ phrase: 'énergie', weight: 0.4 }] },
          ],
        }),
      );

      const list = queryIn(fixture, '[data-testid="for-you-stream-phrases"]') as HTMLElement;
      const text = list.textContent!.replace(/\s+/g, ' ');
      expect(text.indexOf('German')).toBeLessThan(text.indexOf('Kernkraft'));
      expect(text.indexOf('Kernkraft')).toBeLessThan(text.indexOf('Netz'));
      expect(text.indexOf('Netz')).toBeLessThan(text.indexOf('French'));
      const rows = Array.from(list.querySelectorAll('.stream-phrase')).map((row) =>
        Array.from(row.children).map((cell) => cell.textContent),
      );
      expect(rows).toEqual([
        ['Kernkraft', '0.9'],
        ['Netz', '0.3'],
        ['énergie', '0.4'],
      ]);
    });

    it('shows the empty state without phrases', () => {
      const fixture = mount(streamState({ phrases: [] }));

      const list = queryIn(fixture, '[data-testid="for-you-stream-phrases"]') as HTMLElement;
      expect(list.textContent).toContain('No word list yet');
    });

    it('snaps the bar back when the save fails', () => {
      const fixture = mount(streamState({ precision: 'balanced' }));

      click(fixture, '[data-testid="for-you-stream-precision"] button.wider');
      fixture.detectChanges();
      expect(
        queryIn(fixture, '[data-testid="for-you-stream-precision"] .fill')!.style.inlineSize,
      ).toBe('75%');
      http
        .expectOne(STREAM_URL)
        .flush({ title: 'boom' }, { status: 500, statusText: 'Server Error' });
      fixture.detectChanges();

      expect(
        queryIn(fixture, '[data-testid="for-you-stream-precision"] .fill')!.style.inlineSize,
      ).toBe('50%');
    });
  });
});
