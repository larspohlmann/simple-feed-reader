import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { ToastService } from '../shared/toast/toast.service';
import { RecommendationsService } from '../reader/recommendations.service';
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
    favoritesCap: 50,
    keptCap: 50,
    viewedCap: 200,
    candidatePoolSize: 400,
    picksLimit: 20,
    batchCount: null,
    contextWindow: 128000,
    contextWindowOverride: null,
    contextWindowSource: 'provider',
    debugEnabled: false,
    autoGenerateIntervalHours: null,
    lookbackDays: 2,
    workerAlive: true,
    profileText: null,
    showReasons: false,
  };

  function mount(
    initial: RecommendationSettingsState = STATE,
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

  const showReasonsToggle = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLInputElement =>
    fixture.nativeElement.querySelector('app-settings-row app-toggle input[type="checkbox"]');

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

  beforeEach(() => {
    dialogStub.open.mockReset();
    toastStub.show.mockReset();
  });

  afterEach(() => http.verify());

  it('renders the loaded values into the fields', () => {
    const fixture = mount();

    expect(fixture.componentInstance.favoritesCap()).toBe(50);
    expect(fixture.componentInstance.picksLimit()).toBe(20);
    expect(fixture.componentInstance.batchCount()).toBeNull();
    expect(fixture.componentInstance.contextWindow()).toBeNull();
    expect(fixture.componentInstance.guidance()).toBe('');
    expect(fixture.componentInstance.debugEnabled()).toBe(false);
    expect(fixture.componentInstance.showReasons()).toBe(false);
  });

  it('shows the fixed prompt, read-only, inside a disclosure', () => {
    const fixture = mount();

    const pre = fixture.nativeElement.querySelector('details pre.fixed') as HTMLElement;
    expect(pre.textContent).toContain('You are the recommendation engine for a feed reader.');
    expect(pre.textContent).toContain('Return at most 20 picks as a JSON array of entry ids.');
  });

  it('renders the six numeric tuning fields inside the expert drill-in', () => {
    const fixture = mount();

    const grid = fixture.nativeElement.querySelector('details .expert-grid') as HTMLElement;
    const labels = Array.from(grid.querySelectorAll('label')).map((el) => el.textContent?.trim());
    expect(labels).toEqual(
      expect.arrayContaining([
        expect.stringContaining('Favorites in history'),
        expect.stringContaining('Kept in history'),
        expect.stringContaining('Viewed in history'),
        expect.stringContaining('Maximum articles'),
        expect.stringContaining('Maximum picks'),
        expect.stringContaining('Batches (empty = automatic)'),
      ]),
    );
  });

  describe('the show-reasons switch (instant)', () => {
    it('persists immediately with showReasons in the PUT body', () => {
      const fixture = mount();

      const toggle = showReasonsToggle(fixture);
      toggle.checked = true;
      toggle.dispatchEvent(new Event('change'));

      const request = http.expectOne('/api/me/ai/recommendations');
      expect(request.request.method).toBe('PUT');
      expect(request.request.body.showReasons).toBe(true);
      request.flush({ ...STATE, showReasons: true });

      expect(fixture.componentInstance.showReasons()).toBe(true);
    });

    it('leaves the dirty flag untouched — it is not a typed edit', () => {
      const fixture = mount();

      fixture.componentInstance.onShowReasons(true);
      http.expectOne('/api/me/ai/recommendations').flush({ ...STATE, showReasons: true });

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

    it('sends the batch count and context window override the user typed', () => {
      const fixture = mount();

      fixture.componentInstance.onBatchCountInput({
        target: { value: '10' },
      } as unknown as Event);
      fixture.componentInstance.onContextWindowInput({
        target: { value: '64000' },
      } as unknown as Event);
      fixture.detectChanges();

      saveButton(fixture).click();

      const request = http.expectOne('/api/me/ai/recommendations');
      expect(request.request.body).toEqual(
        expect.objectContaining({ batchCount: 10, contextWindow: 64000 }),
      );
      request.flush({ ...STATE, batchCount: 10 });
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

    it('shows the error banner when the save is rejected as invalid', () => {
      const fixture = mount();

      const input = picksInput(fixture);
      input.value = '9999';
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

      expect(banner(fixture)?.textContent).toContain('One or more fields are invalid.');
    });
  });

  describe('the success toast', () => {
    it('fires once on an actual persist success, not on the click', () => {
      const fixture = mount();

      fixture.componentInstance.onShowReasons(true);
      // No toast yet: the PUT is in flight.
      expect(toastStub.show).not.toHaveBeenCalled();

      http.expectOne('/api/me/ai/recommendations').flush({ ...STATE, showReasons: true });
      fixture.detectChanges();

      expect(toastStub.show).toHaveBeenCalledTimes(1);
      expect(toastStub.show).toHaveBeenCalledWith({ message: 'Saved.' });
    });

    it('stays silent when a save is rejected', () => {
      const fixture = mount();

      const input = picksInput(fixture);
      input.value = '9999';
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
    expect(triggers.map((el) => el.getAttribute('aria-label'))).toEqual([
      'Favorites in history',
      'Kept in history',
      'Viewed in history',
      'Maximum articles',
      'Maximum picks',
      'Batches (empty = automatic)',
    ]);
  });

  it('shows the persisted preference profile read-only when present', () => {
    const fixture = mount({ ...STATE, profileText: 'Likes self-hosted tooling and Rust.' });

    const el = fixture.nativeElement.querySelector('[data-testid="recommendation-profile"]');
    expect(el?.textContent).toContain('Likes self-hosted tooling and Rust.');
    expect(el?.querySelector('textarea')).toBeNull();
  });

  it('hides the profile block when no profile has been generated yet', () => {
    const fixture = mount({ ...STATE, profileText: null });

    expect(
      fixture.nativeElement.querySelector('[data-testid="recommendation-profile"]'),
    ).toBeNull();
  });

  describe('clearing recommendations', () => {
    it('does nothing until the confirm dialog resolves true', () => {
      const fixture = mount();
      dialogStub.open.mockReturnValue({ closed: of(false) });

      fixture.componentInstance.confirmPurge();

      http.expectNone((r) => r.method === 'DELETE');
    });

    it('purges, refreshes the recommendation status and shows the confirmation line', () => {
      const fixture = mount();
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
        forYou: { itemCount: 3, generatedAt: '2026-08-08T09:00:00Z', newestRunId: null },
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
        forYou: { itemCount: 0, generatedAt: null, newestRunId: null },
      });
      fixture.detectChanges();

      expect(fixture.nativeElement.textContent).toContain('Recommendations cleared.');
      expect(TestBed.inject(RecommendationsService).forYouCount()).toBe(0);
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
      expect(config.data.confirmLabel).toBe('Clear recommendations');
      expect(config.data.danger).toBe(true);
    });
  });
});
