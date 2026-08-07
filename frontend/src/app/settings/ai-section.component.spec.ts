import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { AiAvailabilityService, AiState } from '../core/ai-availability.service';
import { API_BASE_URL } from '../core/api';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AiSectionComponent } from './ai-section.component';

describe('AiSectionComponent', () => {
  let http: HttpTestingController;

  const UNCONFIGURED: AiState = {
    configured: false,
    baseUrl: null,
    apiKeyHint: null,
    model: null,
    ready: false,
  };

  const CONFIGURED: AiState = {
    configured: true,
    baseUrl: 'https://api.example.test/v1',
    apiKeyHint: '1234',
    model: null,
    ready: false,
  };

  const RECOMMENDATIONS = {
    guidancePrompt: null,
    defaultGuidancePrompt: 'Prefer long-form articles.',
    fixedPrompt: { role: 'role', outputContract: 'contract' },
    favoritesCap: 50,
    keptCap: 50,
    viewedCap: 200,
    candidatePoolSize: 400,
    picksLimit: 20,
    contextWindow: 128000,
    contextWindowOverride: null,
    contextWindowSource: 'provider',
    debugEnabled: false,
  };

  /** The recommendation card mounts, and fires its own GET, only once AI is
   *  ready — every test that reaches that state has to drain it too. */
  function flushRecommendations(fixture: ComponentFixture<AiSectionComponent>): void {
    http.expectOne('/api/me/ai/recommendations').flush(RECOMMENDATIONS);
    fixture.detectChanges();
  }

  function mount(initial: AiState = UNCONFIGURED): ComponentFixture<AiSectionComponent> {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(AiSectionComponent);
    fixture.detectChanges();
    http.expectOne('/api/me/ai').flush(initial);
    fixture.detectChanges();
    return fixture;
  }

  function saveConnection(fixture: ComponentFixture<AiSectionComponent>, key = 'sk-abcdef1234') {
    fixture.componentInstance.baseUrl.set('https://api.example.test/v1');
    fixture.componentInstance.apiKey.set(key);
    fixture.componentInstance.saveConnection();
  }

  const banner = (fixture: ComponentFixture<AiSectionComponent>): HTMLElement | null =>
    fixture.nativeElement.querySelector('app-error-banner');

  afterEach(() => http.verify());

  it('offers no model select before a connection is saved', () => {
    const fixture = mount();
    expect(fixture.nativeElement.querySelector('app-searchable-select')).toBeNull();
  });

  it('shows the model select once the connection save returns models', () => {
    const fixture = mount();
    saveConnection(fixture);

    http.expectOne('/api/me/ai/connection').flush({
      ...CONFIGURED,
      models: ['gpt-4o', 'gpt-4o-mini'],
    });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('app-searchable-select')).not.toBeNull();
  });

  it('clears the typed key after the connection is saved', () => {
    const fixture = mount();
    saveConnection(fixture);
    http.expectOne('/api/me/ai/connection').flush({ ...CONFIGURED, models: ['gpt-4o'] });

    expect(fixture.componentInstance.apiKey()).toBe('');
  });

  it('never sends the key anywhere but the connection body', () => {
    const fixture = mount();
    saveConnection(fixture, 'sk-secret-value');
    const request = http.expectOne('/api/me/ai/connection');

    expect(request.request.body).toEqual({
      baseUrl: 'https://api.example.test/v1',
      apiKey: 'sk-secret-value',
    });
    expect(request.request.urlWithParams).toBe('/api/me/ai/connection');

    request.flush({ ...CONFIGURED, models: ['gpt-4o'] });
    fixture.detectChanges();

    // After the response, not before it: a success handler that stashed the key
    // would slip past an assertion made while the request was still in flight.
    expect(JSON.stringify(localStorage)).not.toContain('sk-secret-value');
    expect(JSON.stringify(sessionStorage)).not.toContain('sk-secret-value');
  });

  // The failing save is the interesting one. A key that outlives the request it
  // was typed for is exactly what "never persisted" has to rule out, and a
  // rejection is when a naive retry design would be tempted to keep it around.
  it('keeps no trace of the key when the save fails', () => {
    const fixture = mount();
    saveConnection(fixture, 'sk-doomed-value');
    const request = http.expectOne('/api/me/ai/connection');

    request.flush(
      {
        type: 'ai_provider_rejected',
        title: 'The AI provider could not be used',
        status: 422,
        detail: 'That provider refused the API key.',
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    fixture.detectChanges();

    expect(fixture.componentInstance.apiKey()).toBe('');
    expect(JSON.stringify(localStorage)).not.toContain('sk-doomed-value');
    expect(JSON.stringify(sessionStorage)).not.toContain('sk-doomed-value');
    expect(request.request.urlWithParams).not.toContain('sk-doomed-value');
    expect(fixture.nativeElement.textContent).not.toContain('sk-doomed-value');
  });

  it('surfaces the provider refusal in the words the server sent', () => {
    const fixture = mount();
    saveConnection(fixture, 'sk-wrong');

    http.expectOne('/api/me/ai/connection').flush(
      {
        type: 'ai_provider_rejected',
        title: 'The AI provider could not be used',
        status: 422,
        detail: 'That provider refused the API key.',
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    fixture.detectChanges();

    expect(banner(fixture)?.textContent).toContain('refused the API key');
  });

  // The assertion deliberately does NOT look for the server's own sentence: the
  // ordinary-refusal fallback renders that sentence verbatim, so an assertion on
  // it would pass even if the classification were deleted. "Retrying will not
  // help" exists only in the translated message this kind resolves to.
  it('tells the account to enter the key again when the stored key cannot be read', () => {
    const fixture = mount(CONFIGURED);
    fixture.componentInstance.ai.refreshModels();

    http.expectOne('/api/me/ai/models').flush(
      {
        type: 'ai_key_unreadable',
        title: 'The stored API key could not be read',
        status: 422,
        detail: 'The stored API key can no longer be read. Enter it again.',
      },
      { status: 422, statusText: 'Unprocessable Content' },
    );
    fixture.detectChanges();

    expect(fixture.componentInstance.ai.failure()?.kind).toBe('unreadableKey');
    expect(banner(fixture)?.textContent).toContain('Retrying will not help');
    expect(banner(fixture)?.textContent).not.toContain('Enter it again.');
  });

  it('names the rate limit rather than blaming the provider', () => {
    const fixture = mount(CONFIGURED);
    fixture.componentInstance.ai.refreshModels();

    http.expectOne('/api/me/ai/models').flush(
      {
        type: 'rate_limited',
        title: 'Too many requests',
        status: 429,
        detail: 'Too many attempts. Try again later.',
      },
      { status: 429, statusText: 'Too Many Requests' },
    );
    fixture.detectChanges();

    expect(banner(fixture)?.textContent).toContain('Wait a few minutes');
  });

  it('publishes the saved model to the app-wide availability signal', () => {
    const fixture = mount();
    saveConnection(fixture);
    http.expectOne('/api/me/ai/connection').flush({ ...CONFIGURED, models: ['gpt-4o'] });
    fixture.detectChanges();

    fixture.componentInstance.chosenModel.set('gpt-4o');
    fixture.componentInstance.saveModel();
    http.expectOne('/api/me/ai/model').flush({ ...CONFIGURED, model: 'gpt-4o', ready: true });
    fixture.detectChanges();
    flushRecommendations(fixture);

    const availability = TestBed.inject(AiAvailabilityService);
    expect(availability.ready()).toBe(true);
    expect(availability.model()).toBe('gpt-4o');
  });

  it('drops the provider, and the availability with it, when it is removed', () => {
    const fixture = mount({ ...CONFIGURED, model: 'gpt-4o', ready: true });
    flushRecommendations(fixture);
    expect(TestBed.inject(AiAvailabilityService).ready()).toBe(true);

    fixture.componentInstance.ai.forget();
    http.expectOne('/api/me/ai').flush(null, { status: 204, statusText: 'No Content' });
    fixture.detectChanges();

    expect(TestBed.inject(AiAvailabilityService).ready()).toBe(false);
    expect(fixture.componentInstance.ai.state().configured).toBe(false);
    expect(fixture.componentInstance.baseUrl()).toBe('');
  });

  it('shows the recommendation settings card once AI is ready, and not before', () => {
    const notReady = mount(CONFIGURED);
    expect(notReady.nativeElement.querySelector('app-recommendation-settings-card')).toBeNull();

    const ready = mount({ ...CONFIGURED, model: 'gpt-4o', ready: true });
    flushRecommendations(ready);

    expect(ready.nativeElement.querySelector('app-recommendation-settings-card')).not.toBeNull();
  });
});
