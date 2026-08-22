// src/app/settings/recommendation-settings.service.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../core/api';
import { ReaderApi } from '../reader/reader-api';
import { RecommendationsService } from '../reader/recommendations.service';
import {
  RecommendationSettingsService,
  RecommendationSettingsState,
} from './recommendation-settings.service';

const BASE = 'https://api.test';
const ENDPOINT = `${BASE}/api/me/ai/recommendations`;

function state(over: Partial<RecommendationSettingsState> = {}): RecommendationSettingsState {
  return {
    guidancePrompt: null,
    defaultGuidancePrompt: 'Prefer long-form articles.',
    fixedPrompt: { role: 'role', outputContract: 'contract' },
    favoritesCap: 50,
    keptCap: 50,
    viewedCap: 200,
    candidatePoolSize: 400,
    lookbackDays: 2,
    picksLimit: 20,
    batchCount: null,
    contextWindow: 128000,
    contextWindowOverride: null,
    contextWindowSource: 'provider',
    debugEnabled: false,
    autoGenerateIntervalHours: null,
    workerAlive: true,
    profileText: null,
    showReasons: false,
    ...over,
  };
}

describe('RecommendationSettingsService', () => {
  let service: RecommendationSettingsService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        RecommendationSettingsService,
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        { provide: ReaderApi, useValue: {} },
        { provide: RecommendationsService, useValue: {} },
      ],
    });
    service = TestBed.inject(RecommendationSettingsService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  function loadState(over: Partial<RecommendationSettingsState> = {}): void {
    service.load();
    http.expectOne(ENDPOINT).flush(state(over));
  }

  it('saveInstant path: showReasons issues an immediate PUT with the full body', () => {
    loadState();

    service.saveInstant({ showReasons: true });

    const put = http.expectOne(ENDPOINT);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body.showReasons).toBe(true);
    expect(put.request.body.favoritesCap).toBe(50);
    expect(put.request.body.contextWindow).toBeNull();

    put.flush(state({ showReasons: true }));
    expect(service.state()?.showReasons).toBe(true);
  });

  it('typed path: a cap edit sets dirty and issues no PUT until save()', () => {
    loadState();

    service.setTypedField('favoritesCap', 99);
    expect(service.dirty()).toBe(true);
    http.expectNone(ENDPOINT);

    service.save();
    const put = http.expectOne(ENDPOINT);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body.favoritesCap).toBe(99);

    put.flush(state({ favoritesCap: 99 }));
    expect(service.dirty()).toBe(false);
  });

  it('instant path does not commit pending typed edits', () => {
    loadState();

    service.setTypedField('favoritesCap', 99);
    expect(service.dirty()).toBe(true);

    service.saveInstant({ showReasons: true });
    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.showReasons).toBe(true);
    expect(put.request.body.favoritesCap).toBe(50);

    put.flush(state({ showReasons: true }));
    expect(service.dirty()).toBe(true);
  });

  it('discardDraft clears the pending typed edits and the dirty flag', () => {
    loadState();

    service.setTypedField('favoritesCap', 99);
    expect(service.dirty()).toBe(true);

    service.discardDraft();
    expect(service.dirty()).toBe(false);

    // The dropped edit must not resurface: a later save sends server truth.
    service.save();
    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.favoritesCap).toBe(50);
    put.flush(state());
  });

  it('saveInstant flips saved on success, so the card toasts uniformly', () => {
    loadState();
    expect(service.saved()).toBe(false);

    service.saveInstant({ showReasons: true });
    http.expectOne(ENDPOINT).flush(state({ showReasons: true }));

    expect(service.saved()).toBe(true);
  });
});
