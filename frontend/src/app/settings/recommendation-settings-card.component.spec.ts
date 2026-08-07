import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../core/api';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { RecommendationSettingsCardComponent } from './recommendation-settings-card.component';
import { RecommendationSettingsState } from './recommendation-settings.service';

describe('RecommendationSettingsCardComponent', () => {
  let http: HttpTestingController;

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
    contextWindow: 128000,
    contextWindowOverride: null,
    contextWindowSource: 'provider',
    debugEnabled: false,
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

  afterEach(() => http.verify());

  it('renders the loaded values into the fields', () => {
    const fixture = mount();

    expect(fixture.componentInstance.favoritesCap()).toBe(50);
    expect(fixture.componentInstance.keptCap()).toBe(50);
    expect(fixture.componentInstance.viewedCap()).toBe(200);
    expect(fixture.componentInstance.candidatePoolSize()).toBe(400);
    expect(fixture.componentInstance.picksLimit()).toBe(20);
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

  it('sends the full PUT body on save, with a blank context window as null', () => {
    const fixture = mount();

    fixture.componentInstance.picksLimit.set(30);
    fixture.componentInstance.contextWindow.set(null);
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
      contextWindow: null,
      debugEnabled: true,
    });

    request.flush({ ...STATE, picksLimit: 30, contextWindow: 128000, debugEnabled: true });
    fixture.detectChanges();

    expect(fixture.componentInstance.svc.saved()).toBe(true);
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
});
