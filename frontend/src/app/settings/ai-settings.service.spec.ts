// src/app/settings/ai-settings.service.spec.ts
import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  TestRequest,
  provideHttpClientTesting,
} from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { AiAvailabilityService } from '../core/ai-availability.service';
import { API_BASE_URL } from '../core/api';
import { AiConfig, AiConfigList, AiSettingsService } from './ai-settings.service';

const base = 'https://api.test';

const config = (over: Partial<AiConfig> = {}): AiConfig => ({
  id: 1,
  name: null,
  baseUrl: 'https://api.example.test/v1',
  apiKeyHint: '1234',
  model: null,
  ready: false,
  active: false,
  suppressReasoning: true,
  batchConcurrency: 1,
  ...over,
});

describe('AiSettingsService', () => {
  let svc: AiSettingsService;
  let ctrl: HttpTestingController;
  let availability: AiAvailabilityService;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: base },
        AiSettingsService,
      ],
    });
    svc = TestBed.inject(AiSettingsService);
    ctrl = TestBed.inject(HttpTestingController);
    availability = TestBed.inject(AiAvailabilityService);
  });

  afterEach(() => {
    ctrl.verify();
    jest.useRealTimers();
  });

  it('loads the list and follows the active configuration', () => {
    svc.load();
    const request = ctrl.expectOne(`${base}/api/me/ai`);
    expect(request.request.method).toBe('GET');

    const list: AiConfigList = {
      configs: [config({ id: 1 }), config({ id: 2, active: true, ready: true, model: 'gpt-4o' })],
      activeId: 2,
    };
    request.flush(list);

    expect(svc.configs()).toEqual(list.configs);
    expect(svc.activeId()).toBe(2);
    expect(availability.ready()).toBe(true);
    expect(availability.model()).toBe('gpt-4o');
  });

  it('reports no availability when nothing is active', () => {
    svc.load();
    ctrl.expectOne(`${base}/api/me/ai`).flush({ configs: [config()], activeId: null });

    expect(availability.ready()).toBe(false);
    expect(availability.model()).toBeNull();
  });

  it('adds a configuration, stores its models and opens the model picker for it', () => {
    svc.add(DRAFT, jest.fn());
    const request = ctrl.expectOne(`${base}/api/me/ai/configs`);
    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({
      name: 'My provider',
      baseUrl: 'https://api.example.test/v1',
      apiKey: 'sk-secret',
    });

    request.flush({ ...config({ id: 7, name: 'My provider' }), models: ['gpt-4o', 'gpt-4o-mini'] });

    expect(svc.configs()).toEqual([config({ id: 7, name: 'My provider' })]);
    expect(svc.models()).toEqual(['gpt-4o', 'gpt-4o-mini']);
    expect(svc.choosingModelFor()).toBe(7);
  });

  // A second, non-active configuration must not disturb the availability the
  // account already relies on for recommendations.
  it('does not change availability when a second, non-active config is added', () => {
    svc.load();
    ctrl.expectOne(`${base}/api/me/ai`).flush({
      configs: [config({ id: 1, active: true, ready: true, model: 'gpt-4o' })],
      activeId: 1,
    });

    svc.add({ name: null, baseUrl: 'https://other.test/v1', apiKey: 'sk-other' }, jest.fn());
    ctrl.expectOne(`${base}/api/me/ai/configs`).flush({ ...config({ id: 2 }), models: ['claude'] });

    expect(availability.ready()).toBe(true);
    expect(availability.model()).toBe('gpt-4o');
    expect(svc.activeId()).toBe(1);
  });

  it('loads models for a configuration and opens its picker', () => {
    svc.loadModels(3);
    const request = ctrl.expectOne(`${base}/api/me/ai/configs/3/models`);
    expect(request.request.method).toBe('GET');

    request.flush({ models: ['gpt-4o'] });

    expect(svc.models()).toEqual(['gpt-4o']);
    expect(svc.choosingModelFor()).toBe(3);
  });

  it('chooses a model and upserts the returned configuration', () => {
    svc.load();
    ctrl.expectOne(`${base}/api/me/ai`).flush({ configs: [config({ id: 5 })], activeId: null });

    svc.chooseModel(5, 'gpt-4o');
    const request = ctrl.expectOne(`${base}/api/me/ai/configs/5/model`);
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ model: 'gpt-4o' });

    request.flush(config({ id: 5, model: 'gpt-4o', ready: true, active: true }));

    expect(svc.configs()).toEqual([config({ id: 5, model: 'gpt-4o', ready: true, active: true })]);
    expect(svc.activeId()).toBe(5);
    expect(availability.ready()).toBe(true);
    expect(availability.model()).toBe('gpt-4o');
  });

  it('renames a configuration in place', () => {
    svc.load();
    ctrl.expectOne(`${base}/api/me/ai`).flush({ configs: [config({ id: 5 })], activeId: null });

    svc.rename(5, 'New name');
    const request = ctrl.expectOne(`${base}/api/me/ai/configs/5/name`);
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ name: 'New name' });

    request.flush(config({ id: 5, name: 'New name' }));

    expect(svc.configs()).toEqual([config({ id: 5, name: 'New name' })]);
  });

  it('sets the reasoning preference in place', () => {
    svc.load();
    ctrl.expectOne(`${base}/api/me/ai`).flush({ configs: [config({ id: 5 })], activeId: null });

    svc.setReasoning(5, false);
    const request = ctrl.expectOne(`${base}/api/me/ai/configs/5/reasoning`);
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ suppressReasoning: false });
    request.flush(config({ id: 5, suppressReasoning: false }));

    expect(svc.configs()[0].suppressReasoning).toBe(false);
  });

  it('posts batch concurrency and upserts the returned config', () => {
    svc.setBatchConcurrency(7, 3);
    const request = ctrl.expectOne(`${base}/api/me/ai/configs/7/batch-concurrency`);
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ batchConcurrency: 3 });
    request.flush(config({ id: 7, batchConcurrency: 3 }));

    expect(svc.configs().find((each) => each.id === 7)?.batchConcurrency).toBe(3);
  });

  it('marks the saved row on a successful concurrency save, then clears it after 2.5s', () => {
    jest.useFakeTimers();

    svc.setBatchConcurrency(7, 3);
    expect(svc.savedConcurrencyId()).toBeNull();

    ctrl
      .expectOne(`${base}/api/me/ai/configs/7/batch-concurrency`)
      .flush(config({ id: 7, batchConcurrency: 3 }));
    expect(svc.savedConcurrencyId()).toBe(7);

    jest.advanceTimersByTime(2500);
    expect(svc.savedConcurrencyId()).toBeNull();
  });

  it('cancels the previous row-save timer so it cannot clobber a later row', () => {
    jest.useFakeTimers();

    // Row 7 saves at t=0; its auto-clear is scheduled for t=2500.
    svc.setBatchConcurrency(7, 3);
    ctrl
      .expectOne(`${base}/api/me/ai/configs/7/batch-concurrency`)
      .flush(config({ id: 7, batchConcurrency: 3 }));
    expect(svc.savedConcurrencyId()).toBe(7);

    // Row 8 saves at t=2000, well before row 7's timer would fire. Its own
    // auto-clear lands at t=4500.
    jest.advanceTimersByTime(2000);
    svc.setBatchConcurrency(8, 2);
    ctrl
      .expectOne(`${base}/api/me/ai/configs/8/batch-concurrency`)
      .flush(config({ id: 8, batchConcurrency: 2 }));
    expect(svc.savedConcurrencyId()).toBe(8);

    // t=2500: row 7's original timer would fire here if it survived —
    // proving it was cancelled rather than merely superseded.
    jest.advanceTimersByTime(500);
    expect(svc.savedConcurrencyId()).toBe(8);

    // t=4500: row 8's own timer fires.
    jest.advanceTimersByTime(2000);
    expect(svc.savedConcurrencyId()).toBeNull();
  });

  it('does not mark a save as done when the request fails', () => {
    svc.setBatchConcurrency(7, 3);
    ctrl
      .expectOne(`${base}/api/me/ai/configs/7/batch-concurrency`)
      .flush({ title: 'Bad request' }, { status: 400, statusText: 'Bad Request' });

    expect(svc.savedConcurrencyId()).toBeNull();
  });

  it('activates a configuration and clears the active flag on the others', () => {
    svc.load();
    ctrl.expectOne(`${base}/api/me/ai`).flush({
      configs: [
        config({ id: 1, active: true, ready: true, model: 'gpt-4o' }),
        config({ id: 2, ready: true, model: 'claude' }),
      ],
      activeId: 1,
    });

    svc.activate(2);
    const request = ctrl.expectOne(`${base}/api/me/ai/configs/2/active`);
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({});

    request.flush(config({ id: 2, active: true, ready: true, model: 'claude' }));

    expect(svc.configs()).toEqual([
      config({ id: 1, active: false, ready: true, model: 'gpt-4o' }),
      config({ id: 2, active: true, ready: true, model: 'claude' }),
    ]);
    expect(svc.activeId()).toBe(2);
    expect(availability.model()).toBe('claude');
  });

  it('duplicates a configuration and adds the copy to the list', () => {
    svc.configs.set([
      config({ id: 1, name: 'Work OpenAI', active: true, ready: true, model: 'gpt-4o' }),
    ]);

    svc.duplicate(1);
    const request = ctrl.expectOne(`${base}/api/me/ai/configs/1/duplicate`);
    expect(request.request.method).toBe('POST');

    request.flush(config({ id: 2, name: 'Copy of Work OpenAI' }));

    expect(svc.configs().map((each) => each.id)).toEqual([1, 2]);
    expect(svc.configs()[1].name).toBe('Copy of Work OpenAI');
    expect(svc.configs()[1].active).toBe(false);
  });

  it('drops a configuration and resets availability when the active one is removed', () => {
    svc.load();
    ctrl.expectOne(`${base}/api/me/ai`).flush({
      configs: [config({ id: 1, active: true, ready: true, model: 'gpt-4o' })],
      activeId: 1,
    });

    svc.remove(1);
    const request = ctrl.expectOne(`${base}/api/me/ai/configs/1`);
    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });

    expect(svc.configs()).toEqual([]);
    expect(svc.activeId()).toBeNull();
    expect(availability.ready()).toBe(false);
    expect(availability.model()).toBeNull();
  });

  it('drops a non-active configuration without disturbing availability', () => {
    svc.load();
    ctrl.expectOne(`${base}/api/me/ai`).flush({
      configs: [
        config({ id: 1, active: true, ready: true, model: 'gpt-4o' }),
        config({ id: 2, ready: true, model: 'claude' }),
      ],
      activeId: 1,
    });

    svc.remove(2);
    ctrl
      .expectOne(`${base}/api/me/ai/configs/2`)
      .flush(null, { status: 204, statusText: 'No Content' });

    expect(svc.configs()).toEqual([config({ id: 1, active: true, ready: true, model: 'gpt-4o' })]);
    expect(svc.activeId()).toBe(1);
    expect(availability.ready()).toBe(true);
  });

  it('maps a 429 to the rate-limited failure', () => {
    svc.load();
    ctrl
      .expectOne(`${base}/api/me/ai`)
      .flush(
        { type: 'rate_limited', title: 'Too many requests', status: 429, detail: 'Wait.' },
        { status: 429, statusText: 'Too Many Requests' },
      );

    expect(svc.failure()?.failure.kind).toBe('rateLimited');
    expect(svc.busy()).toBe(false);
  });

  it('maps a 409 ai_configuration_limit to the limit failure', () => {
    svc.add({ name: null, baseUrl: 'https://api.example.test/v1', apiKey: 'sk-secret' }, jest.fn());
    ctrl.expectOne(`${base}/api/me/ai/configs`).flush(
      {
        type: 'ai_configuration_limit',
        title: 'Too many AI configurations',
        status: 409,
        detail: 'This account already holds the maximum number of AI configurations.',
      },
      { status: 409, statusText: 'Conflict' },
    );

    expect(svc.failure()?.failure.kind).toBe('limit');
  });

  const DRAFT = {
    name: 'My provider',
    baseUrl: 'https://api.example.test/v1',
    apiKey: 'sk-secret',
  };

  const reject = (request: TestRequest, body: Record<string, unknown> | null, status = 422): void =>
    request.flush(body, { status, statusText: 'Unprocessable Content' });

  it('scopes a failed add to the add form', () => {
    const onAdded = jest.fn();
    svc.add(DRAFT, onAdded);

    reject(ctrl.expectOne(`${base}/api/me/ai/configs`), {
      type: 'validation_error',
      title: 'Validation failed',
      status: 422,
      detail: 'One or more fields are invalid.',
      errors: { apiKey: ['This value is too short.'] },
    });

    expect(svc.failure()?.scope).toEqual({ action: 'add' });
    expect(svc.failure()?.failure.kind).toBe('validation');
    expect(onAdded).not.toHaveBeenCalled();
  });

  it('tells the caller the add landed, so the draft is cleared only then', () => {
    const onAdded = jest.fn();
    svc.add(DRAFT, onAdded);

    ctrl.expectOne(`${base}/api/me/ai/configs`).flush({ ...config({ id: 4 }), models: ['gpt-4o'] });

    expect(onAdded).toHaveBeenCalledTimes(1);
    expect(svc.failure()).toBeNull();
  });

  it('scopes a failed row write to that row', () => {
    svc.load();
    ctrl.expectOne(`${base}/api/me/ai`).flush({ configs: [config({ id: 9 })], activeId: null });

    svc.loadModels(9);
    reject(ctrl.expectOne(`${base}/api/me/ai/configs/9/models`), {
      type: 'ai_provider_rejected',
      title: 'The AI provider could not be used',
      status: 422,
      detail: 'That address did not answer.',
    });

    expect(svc.failure()?.scope).toEqual({ action: 'row', configId: 9 });
    expect(svc.failure()?.failure.detail).toBe('That address did not answer.');
  });

  it('scopes a failed list load to the list', () => {
    svc.load();
    reject(ctrl.expectOne(`${base}/api/me/ai`), null, 500);

    expect(svc.failure()?.scope).toEqual({ action: 'load' });
  });

  it('clears the previous failure when the next write starts', () => {
    svc.load();
    reject(ctrl.expectOne(`${base}/api/me/ai`), null, 500);
    expect(svc.failure()).not.toBeNull();

    svc.activate(3);
    expect(svc.failure()).toBeNull();
    ctrl.expectOne(`${base}/api/me/ai/configs/3/active`).flush(config({ id: 3, active: true }));
  });
});
