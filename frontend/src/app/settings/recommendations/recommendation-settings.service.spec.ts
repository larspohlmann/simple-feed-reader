import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { ReaderApi } from '../../reader/reader-api';
import { RecommendationsService } from '../../reader/state/recommendations.service';
import { SubscriptionsStore } from '../../reader/state/subscriptions.store';
import {
  RecommendationSettingsService,
  RecommendationSettingsState,
} from './recommendation-settings.service';

const BASE = 'https://api.test';
const ENDPOINT = `${BASE}/api/me/ai/recommendations`;

function state(over: Partial<RecommendationSettingsState> = {}): RecommendationSettingsState {
  const { expertBounds, expertDefaults, ...otherOverrides } = over;

  return {
    guidancePrompt: null,
    defaultGuidancePrompt: 'Prefer long-form articles.',
    fixedPrompt: { role: 'role', outputContract: 'contract' },
    favoritesCap: 50,
    candidatePoolSize: 400,
    lookbackDays: 2,
    picksLimit: 20,
    batchSize: 'medium',
    contextWindow: 128000,
    contextWindowOverride: null,
    contextWindowSource: 'provider',
    debugEnabled: false,
    autoGenerateIntervalHours: null,
    workerAlive: true,
    showScoreAndReasons: false,
    forYouStream: {
      enabled: true,
      precision: 'balanced',
      defaultPrecision: 'wide',
      phrases: [],
    },
    ...otherOverrides,
    expertDefaults: expertDefaults ?? {
      guidancePrompt: null,
      favoritesCap: 40,
      candidatePoolSize: 500,
      picksLimit: 50,
      batchSize: 'medium',
      contextWindow: null,
    },
    expertBounds: expertBounds ?? {
      favoritesCap: { min: 0, max: 500 },
      candidatePoolSize: { min: 10, max: 5000 },
      picksLimit: { min: 1, max: 500 },
      contextWindow: { min: 4096, max: 2097152 },
    },
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
        { provide: SubscriptionsStore, useValue: { reloadCountsQuietly: jest.fn() } },
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

  it('saveInstant path: showScoreAndReasons issues an immediate PUT with the full body', () => {
    loadState();

    service.saveInstant({ showScoreAndReasons: true });

    const put = http.expectOne(ENDPOINT);
    expect(put.request.method).toBe('PUT');
    expect(put.request.body.showScoreAndReasons).toBe(true);
    expect(put.request.body.favoritesCap).toBe(50);
    expect(put.request.body.contextWindow).toBeNull();

    put.flush(state({ showScoreAndReasons: true }));
    expect(service.state()?.showScoreAndReasons).toBe(true);
  });

  it('sends forYouStream in every body built from state', () => {
    loadState({
      forYouStream: { enabled: false, precision: 'narrow', defaultPrecision: 'wide', phrases: [] },
    });

    service.saveInstant({ debugEnabled: true });

    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.forYouStream).toEqual({ enabled: false, precision: 'narrow' });
    put.flush(state());
  });

  it('reloads the sidebar counts after a stream save, not before', () => {
    const reload = TestBed.inject(SubscriptionsStore).reloadCountsQuietly as jest.Mock;
    loadState();

    service.saveForYouStream({ enabled: false, precision: 'narrowest' });

    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.forYouStream).toEqual({ enabled: false, precision: 'narrowest' });
    expect(reload).not.toHaveBeenCalled();
    put.flush(
      state({
        forYouStream: {
          enabled: false,
          precision: 'narrowest',
          defaultPrecision: 'wide',
          phrases: [],
        },
      }),
    );
    expect(reload).toHaveBeenCalledTimes(1);
    expect(service.state()?.forYouStream.precision).toBe('narrowest');
    expect(service.saved()).toBe(true);
  });

  it('shows the chosen stream settings at once and snaps back when the save fails', () => {
    loadState();

    service.saveForYouStream({ enabled: true, precision: 'widest' });
    expect(service.forYouStream()?.precision).toBe('widest');

    http.expectOne(ENDPOINT).flush({ title: 'boom' }, { status: 500, statusText: 'Server Error' });
    expect(service.forYouStream()?.precision).toBe('balanced');
    expect(service.failure()).not.toBeNull();
  });

  it('keeps the last chosen stream setting when two saves overlap', () => {
    loadState();

    service.saveForYouStream({ enabled: true, precision: 'wide' });
    service.saveForYouStream({ enabled: true, precision: 'widest' });
    const [first, second] = http.match(ENDPOINT);
    const served = (precision: 'wide' | 'widest') =>
      state({ forYouStream: { enabled: true, precision, defaultPrecision: 'wide', phrases: [] } });

    first.flush(served('wide'));
    expect(service.forYouStream()?.precision).toBe('widest');
    second.flush(served('widest'));
    expect(service.forYouStream()?.precision).toBe('widest');
    expect(service.state()?.forYouStream.precision).toBe('widest');
  });

  it('carries a stream choice still in flight in a concurrent instant save', () => {
    loadState();

    service.saveForYouStream({ enabled: false, precision: 'narrow' });
    service.saveInstant({ showScoreAndReasons: true });
    const [stream, instant] = http.match(ENDPOINT);

    expect(stream.request.body.forYouStream).toEqual({ enabled: false, precision: 'narrow' });
    expect(instant.request.body.showScoreAndReasons).toBe(true);
    expect(instant.request.body.forYouStream).toEqual({ enabled: false, precision: 'narrow' });
    stream.flush(state());
    instant.flush(state());
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

    service.saveInstant({ showScoreAndReasons: true });
    const put = http.expectOne(ENDPOINT);
    expect(put.request.body.showScoreAndReasons).toBe(true);
    expect(put.request.body.favoritesCap).toBe(50);

    put.flush(state({ showScoreAndReasons: true }));
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

    service.saveInstant({ showScoreAndReasons: true });
    http.expectOne(ENDPOINT).flush(state({ showScoreAndReasons: true }));

    expect(service.saved()).toBe(true);
  });
});
