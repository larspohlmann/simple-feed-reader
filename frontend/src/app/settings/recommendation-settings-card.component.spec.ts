import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Dialog } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { API_BASE_URL } from '../core/api';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { RecommendationsService } from '../reader/recommendations.service';
import { RecommendationSettingsCardComponent } from './recommendation-settings-card.component';
import { RecommendationSettingsState } from './recommendation-settings.service';

describe('RecommendationSettingsCardComponent', () => {
  let http: HttpTestingController;
  const dialogStub = { open: jest.fn() };

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

  const select = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLSelectElement | null =>
    fixture.nativeElement.querySelector('select[data-testid="auto-generate"]');

  const cronNote = (
    fixture: ComponentFixture<RecommendationSettingsCardComponent>,
  ): HTMLElement | null => fixture.nativeElement.querySelector('.cron-example');

  beforeEach(() => dialogStub.open.mockReset());

  afterEach(() => http.verify());

  it('renders the loaded values into the fields', () => {
    const fixture = mount();

    expect(fixture.componentInstance.favoritesCap()).toBe(50);
    expect(fixture.componentInstance.keptCap()).toBe(50);
    expect(fixture.componentInstance.viewedCap()).toBe(200);
    expect(fixture.componentInstance.candidatePoolSize()).toBe(400);
    expect(fixture.componentInstance.picksLimit()).toBe(20);
    expect(fixture.componentInstance.batchCount()).toBeNull();
    expect(fixture.componentInstance.contextWindow()).toBeNull();
    expect(fixture.componentInstance.guidance()).toBe('');
    expect(fixture.componentInstance.debugEnabled()).toBe(false);
  });

  it('shows the fixed prompt, read-only, inside the details element', () => {
    const fixture = mount();

    const pre = fixture.nativeElement.querySelector('details pre.fixed') as HTMLElement;
    expect(pre.textContent).toContain('You are the recommendation engine for a feed reader.');
    expect(pre.textContent).toContain('Return at most 20 picks as a JSON array of entry ids.');
  });

  it('renders the six numeric tuning fields inside the expert disclosure', () => {
    const fixture = mount();

    const summary = fixture.nativeElement.querySelector(
      'app-disclosure summary',
    ) as HTMLElement | null;
    expect(summary?.textContent).toContain('Expert settings');

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

  it('renders the guidance field and its reset button inside the expert disclosure', () => {
    const fixture = mount({ ...STATE, guidancePrompt: 'Focus on space exploration.' });

    const textarea = fixture.nativeElement.querySelector(
      'details .group textarea',
    ) as HTMLTextAreaElement | null;
    expect(textarea).not.toBeNull();
    expect(textarea!.value).toBe('Focus on space exploration.');

    const resetButton = fixture.nativeElement.querySelector(
      'details .group app-button button',
    ) as HTMLButtonElement | null;
    expect(resetButton).not.toBeNull();
    expect(resetButton!.textContent).toContain('Reset to default');
  });

  it('sends the full PUT body on save, with a blank context window and batch count as null', () => {
    const fixture = mount();

    fixture.componentInstance.picksLimit.set(30);
    fixture.componentInstance.contextWindow.set(null);
    fixture.componentInstance.batchCount.set(null);
    fixture.componentInstance.debugEnabled.set(true);
    fixture.componentInstance.save();

    const request = http.expectOne('/api/me/ai/recommendations');
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({
      guidancePrompt: null,
      favoritesCap: 50,
      keptCap: 50,
      viewedCap: 200,
      candidatePoolSize: 400,
      picksLimit: 30,
      batchCount: null,
      contextWindow: null,
      debugEnabled: true,
      autoGenerateIntervalHours: null,
      lookbackDays: 2,
    });

    request.flush({ ...STATE, picksLimit: 30, contextWindow: 128000, debugEnabled: true });
    fixture.detectChanges();

    expect(fixture.componentInstance.svc.saved()).toBe(true);
  });

  it('sends a numeric batch count when the field is filled in', () => {
    const fixture = mount();

    fixture.componentInstance.batchCount.set(10);
    fixture.componentInstance.save();

    const request = http.expectOne('/api/me/ai/recommendations');
    expect(request.request.body).toEqual(expect.objectContaining({ batchCount: 10 }));
    request.flush({ ...STATE, batchCount: 10 });
  });

  it('sends the numeric context window override when the field is filled in', () => {
    const fixture = mount();

    fixture.componentInstance.contextWindow.set(64000);
    fixture.componentInstance.save();

    const request = http.expectOne('/api/me/ai/recommendations');
    expect(request.request.body).toEqual(expect.objectContaining({ contextWindow: 64000 }));
    request.flush({
      ...STATE,
      contextWindow: 64000,
      contextWindowOverride: 64000,
      contextWindowSource: 'user',
    });
  });

  it('sends guidancePrompt: null after a reset to default', () => {
    const fixture = mount({ ...STATE, guidancePrompt: 'Focus on space exploration.' });
    expect(fixture.componentInstance.guidance()).toBe('Focus on space exploration.');

    fixture.componentInstance.resetGuidance();
    expect(fixture.componentInstance.guidance()).toBe('');

    fixture.componentInstance.save();

    const request = http.expectOne('/api/me/ai/recommendations');
    expect(request.request.body).toEqual(expect.objectContaining({ guidancePrompt: null }));
    request.flush({ ...STATE, guidancePrompt: null });
  });

  it('shows the error banner when the save is rejected as invalid', () => {
    const fixture = mount();

    fixture.componentInstance.picksLimit.set(9999);
    fixture.componentInstance.save();

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

    expect(banner(fixture)).not.toBeNull();
    expect(banner(fixture)?.textContent).toContain('One or more fields are invalid.');
  });

  it('leaves the last value in place when a capped numeric field is cleared', () => {
    const fixture = mount();

    const input = fixture.nativeElement.querySelector(
      'input[min="1"][max="500"]',
    ) as HTMLInputElement;
    input.value = '';
    input.dispatchEvent(new Event('input'));

    // +'' === 0, which is below picksLimit's own min="1" -- a naive
    // coercion would silently arm a save that 422s.
    expect(fixture.componentInstance.picksLimit()).toBe(20);
  });

  it('accepts a typed numeric value for a capped field', () => {
    const fixture = mount();

    const input = fixture.nativeElement.querySelector(
      'input[min="1"][max="500"]',
    ) as HTMLInputElement;
    input.value = '30';
    input.dispatchEvent(new Event('input'));

    expect(fixture.componentInstance.picksLimit()).toBe(30);
  });

  it('reports the effective context window source', () => {
    const providerFixture = mount({ ...STATE, contextWindowSource: 'provider' });
    expect(providerFixture.nativeElement.textContent).toContain('Reported by your provider');

    const fallbackFixture = mount({ ...STATE, contextWindowSource: 'fallback' });
    expect(fallbackFixture.nativeElement.textContent).toContain('Built-in default');

    const userFixture = mount({
      ...STATE,
      contextWindowSource: 'user',
      contextWindowOverride: 64000,
    });
    expect(userFixture.nativeElement.textContent).toContain('Your override');
  });

  it('shows the auto-generate dropdown reflecting the saved interval', () => {
    const fixture = mount({ ...STATE, autoGenerateIntervalHours: 3, workerAlive: true });
    const dropdown = select(fixture);
    expect(dropdown).not.toBeNull();
    expect(dropdown!.value).toBe('3');
  });

  it('hides the cron help note while a worker is alive', () => {
    const fixture = mount({ ...STATE, workerAlive: true });
    expect(cronNote(fixture)).toBeNull();
  });

  it('shows the cron help note when no worker is alive', () => {
    const fixture = mount({ ...STATE, workerAlive: false });
    expect(cronNote(fixture)).not.toBeNull();
  });

  it('sends the chosen interval on save', () => {
    const fixture = mount({ ...STATE, autoGenerateIntervalHours: null, workerAlive: true });
    const dropdown = select(fixture)!;
    dropdown.value = '12';
    dropdown.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    fixture.nativeElement.querySelector('app-button[variant="primary"] button')?.click();
    const put = http.expectOne('/api/me/ai/recommendations');
    expect(put.request.method).toBe('PUT');
    expect(put.request.body.autoGenerateIntervalHours).toBe(12);
    put.flush({ ...STATE, autoGenerateIntervalHours: 12, workerAlive: true });
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

      // RecommendationsService.refreshStatus() re-reads the current status so
      // the sidebar count (Task 9) reflects the cleared list.
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
      // The seam this test exists to cover: the sidebar count must actually
      // read the refreshed report, not fall through the `?.` guard on a
      // `forYou` that isn't there (it guards `report`, not `report.forYou`).
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

  it('renders the look-back select outside the expert disclosure', () => {
    const fixture = mount({ ...STATE, lookbackDays: 5 });

    const select = fixture.nativeElement.querySelector(
      '[data-testid="lookback-days"]',
    ) as HTMLSelectElement | null;
    expect(select).not.toBeNull();
    expect(select!.closest('app-disclosure')).toBeNull();
    expect(fixture.componentInstance.lookbackDays()).toBe(5);
  });

  it('sends the chosen look-back window on save', () => {
    const fixture = mount();

    const select = fixture.nativeElement.querySelector(
      '[data-testid="lookback-days"]',
    ) as HTMLSelectElement;
    select.value = '7';
    select.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    fixture.componentInstance.save();

    const request = http.expectOne('/api/me/ai/recommendations');
    expect(request.request.body.lookbackDays).toBe(7);
    request.flush({ ...STATE, lookbackDays: 7 });
  });

  it('gives every expert field an info tip', () => {
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

  it('explains the schedule, the look-back and the context window on their fields', () => {
    const fixture = mount();

    const labelled = (label: string): HTMLButtonElement | undefined =>
      (
        Array.from(
          fixture.nativeElement.querySelectorAll('app-field app-info-tip button.trigger'),
        ) as HTMLButtonElement[]
      ).find((el) => el.getAttribute('aria-label') === label);

    expect(labelled('Auto-generate For you')).toBeDefined();
    expect(labelled('Look back')).toBeDefined();
    expect(labelled('Context window (tokens)')).toBeDefined();
  });

  it('keeps the danger-zone note visible and adds a tip beside it', () => {
    const fixture = mount();

    const zone = fixture.nativeElement.querySelector('.danger-zone') as HTMLElement;
    expect(zone.querySelector('.danger-zone__note')?.textContent).toContain(
      'Removes every recommended post',
    );

    const trigger = zone.querySelector('app-info-tip button.trigger') as HTMLButtonElement;
    trigger.click();
    fixture.detectChanges();
    expect(zone.querySelector('app-info-tip .panel')?.textContent).toContain('cannot be undone');
  });

  it('adds a tip to the debug row without touching the toggle wiring', () => {
    const fixture = mount();

    const row = fixture.nativeElement.querySelector('.debug-row') as HTMLElement;
    expect(row.querySelector('app-info-tip button.trigger')).not.toBeNull();
    expect(row.querySelector('#rec-debug-toggle')).not.toBeNull();
  });
});
