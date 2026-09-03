import { Dialog } from '@angular/cdk/dialog';
import { WritableSignal, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { ConfirmData } from '../shared/confirm-dialog/confirm-dialog.component';
import { AiFailure, ScopedAiFailure } from './ai-failure';
import { AiSectionComponent } from './ai-section.component';
import { AiConfig, AiSettingsService } from './ai-settings.service';
import { RecommendationSettingsState } from './recommendation-settings.service';

interface AiSettingsStub {
  configs: WritableSignal<readonly AiConfig[]>;
  activeId: WritableSignal<number | null>;
  models: WritableSignal<readonly string[]>;
  defaultMaxBatchSize: WritableSignal<number | null>;
  choosingModelFor: WritableSignal<number | null>;
  busy: WritableSignal<boolean>;
  failure: WritableSignal<ScopedAiFailure | null>;
  savedConcurrencyId: WritableSignal<number | null>;
  load: jest.Mock;
  add: jest.Mock;
  loadModels: jest.Mock;
  chooseModel: jest.Mock;
  duplicate: jest.Mock;
  rename: jest.Mock;
  setReasoning: jest.Mock;
  setSlowModel: jest.Mock;
  setMaxBatchSize: jest.Mock;
  setBatchConcurrency: jest.Mock;
  activate: jest.Mock;
  remove: jest.Mock;
}

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
  slowModel: false,
  maxBatchSize: null,
  ...over,
});

const RECOMMENDATIONS: RecommendationSettingsState = {
  guidancePrompt: null,
  defaultGuidancePrompt: 'Prefer long-form articles.',
  fixedPrompt: { role: 'role', outputContract: 'contract' },
  expertDefaults: {
    guidancePrompt: null,
    favoritesCap: 40,
    keptCap: 40,
    viewedCap: 80,
    candidatePoolSize: 500,
    picksLimit: 50,
    batchCount: null,
    contextWindow: null,
  },
  expertBounds: {
    favoritesCap: { min: 0, max: 500 },
    keptCap: { min: 0, max: 500 },
    viewedCap: { min: 0, max: 500 },
    candidatePoolSize: { min: 10, max: 5000 },
    picksLimit: { min: 1, max: 500 },
    batchCount: { min: 1, max: 100 },
    contextWindow: { min: 4096, max: 2097152 },
  },
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
};

function createStub(): AiSettingsStub {
  return {
    configs: signal<readonly AiConfig[]>([]),
    activeId: signal<number | null>(null),
    models: signal<readonly string[]>([]),
    defaultMaxBatchSize: signal<number | null>(null),
    choosingModelFor: signal<number | null>(null),
    busy: signal(false),
    failure: signal<ScopedAiFailure | null>(null),
    savedConcurrencyId: signal<number | null>(null),
    load: jest.fn(),
    add: jest.fn(),
    loadModels: jest.fn(),
    chooseModel: jest.fn(),
    duplicate: jest.fn(),
    rename: jest.fn(),
    setReasoning: jest.fn(),
    setSlowModel: jest.fn(),
    setMaxBatchSize: jest.fn(),
    setBatchConcurrency: jest.fn(),
    activate: jest.fn(),
    remove: jest.fn(),
  };
}

describe('AiSectionComponent', () => {
  let ai: AiSettingsStub;
  let http: HttpTestingController;
  const dialogStub = { open: jest.fn() };

  function mount(): ComponentFixture<AiSectionComponent> {
    ai = createStub();
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: '' },
        { provide: Dialog, useValue: dialogStub },
      ],
    }).overrideComponent(AiSectionComponent, {
      set: { providers: [{ provide: AiSettingsService, useValue: ai }] },
    });
    http = TestBed.inject(HttpTestingController);
    const fixture = TestBed.createComponent(AiSectionComponent);
    fixture.detectChanges();
    return fixture;
  }

  const row = (fixture: ComponentFixture<AiSectionComponent>, index: number): HTMLElement =>
    (fixture.nativeElement as HTMLElement).querySelectorAll('.config-row')[index] as HTMLElement;

  const addDetails = (fixture: ComponentFixture<AiSectionComponent>): HTMLDetailsElement =>
    Array.from((fixture.nativeElement as HTMLElement).querySelectorAll('details')).find((details) =>
      details.querySelector('.add-config'),
    ) as HTMLDetailsElement;

  const expandRow = (fixture: ComponentFixture<AiSectionComponent>, index: number): void => {
    (row(fixture, index).querySelector('summary') as HTMLElement).click();
    fixture.detectChanges();
  };

  const banners = (host: HTMLElement): string[] =>
    Array.from(host.querySelectorAll('app-error-banner')).map((banner) =>
      (banner.textContent ?? '').trim(),
    );

  // The manager is now Grouped regions, not three app-settings-cards (#541):
  // the add-failure banner scopes to `.add-group`; list/load-failure banners
  // scope to `.conn-list`.
  const addCard = (fixture: ComponentFixture<AiSectionComponent>): HTMLElement =>
    (fixture.nativeElement as HTMLElement).querySelector('.add-group') as HTMLElement;

  const listCard = (fixture: ComponentFixture<AiSectionComponent>): HTMLElement =>
    (fixture.nativeElement as HTMLElement).querySelector('.conn-list') as HTMLElement;

  const scoped = (failure: AiFailure, scope: ScopedAiFailure['scope']): ScopedAiFailure => ({
    failure,
    scope,
  });

  const CONFIG = config({ id: 7 });

  const mountWithConfigs = (configs: readonly AiConfig[]): ComponentFixture<AiSectionComponent> => {
    const fixture = mount();
    ai.configs.set(configs);
    fixture.detectChanges();
    if (configs.length) expandRow(fixture, 0);
    return fixture;
  };

  beforeEach(() => dialogStub.open.mockReset());
  afterEach(() => http.verify());

  it('loads the configurations on construction', () => {
    mount();
    expect(ai.load).toHaveBeenCalled();
  });

  it('renders a row per config with its label, hint and model', () => {
    const fixture = mount();
    ai.configs.set([
      config({ id: 1, name: 'My provider', model: 'gpt-4o', apiKeyHint: '9999' }),
      config({ id: 2, model: 'claude' }),
    ]);
    fixture.detectChanges();

    const rows = fixture.nativeElement.querySelectorAll('.config-row');
    expect(rows.length).toBe(2);
    expect(row(fixture, 0).textContent).toContain('My provider');
    expect(row(fixture, 0).textContent).toContain('9999');
    expect(row(fixture, 0).textContent).toContain('gpt-4o');
  });

  it('derives a host · model label when the config has no name', () => {
    const fixture = mount();
    ai.configs.set([
      config({ id: 1, name: null, baseUrl: 'https://api.example.test/v1', model: 'gpt-4o' }),
    ]);
    fixture.detectChanges();

    expect(row(fixture, 0).querySelector('.label')?.textContent).toContain(
      'api.example.test · gpt-4o',
    );
  });

  it('shows the active badge only on the active row', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, active: true }), config({ id: 2 })]);
    ai.activeId.set(1);
    fixture.detectChanges();

    expect(row(fixture, 0).querySelector('.badge')).not.toBeNull();
    expect(row(fixture, 1).querySelector('.badge')).toBeNull();
  });

  it('offers no model select before "change model" is clicked', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1 })]);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('app-searchable-select')).toBeNull();
  });

  it('adds a configuration and clears the draft once the add lands', () => {
    const fixture = mount();
    (addDetails(fixture).querySelector('summary') as HTMLElement).click();
    fixture.detectChanges();

    fixture.componentInstance.newName.set('My provider');
    fixture.componentInstance.newBaseUrl.set('https://api.example.test/v1');
    fixture.componentInstance.newApiKey.set('sk-secret');

    fixture.componentInstance.add();

    expect(ai.add).toHaveBeenCalledWith(
      { name: 'My provider', baseUrl: 'https://api.example.test/v1', apiKey: 'sk-secret' },
      expect.any(Function),
    );
    expect(fixture.componentInstance.newApiKey()).toBe('sk-secret');

    (ai.add.mock.calls[0][1] as () => void)();

    expect(fixture.componentInstance.newName()).toBe('');
    expect(fixture.componentInstance.newBaseUrl()).toBe('');
    expect(fixture.componentInstance.newApiKey()).toBe('');
  });

  it('keeps every typed value when the add is rejected', () => {
    const fixture = mount();
    (addDetails(fixture).querySelector('summary') as HTMLElement).click();
    fixture.detectChanges();

    fixture.componentInstance.newName.set('My provider');
    fixture.componentInstance.newBaseUrl.set('https://api.example.test/v1');
    fixture.componentInstance.newApiKey.set('sk-short');

    fixture.componentInstance.add();
    ai.failure.set(
      scoped({ kind: 'validation', detail: 'Invalid.', fieldErrors: [] }, { action: 'add' }),
    );
    fixture.detectChanges();

    expect(fixture.componentInstance.newName()).toBe('My provider');
    expect(fixture.componentInstance.newBaseUrl()).toBe('https://api.example.test/v1');
    expect(fixture.componentInstance.newApiKey()).toBe('sk-short');
  });

  it('sends no name when the optional field is left blank', () => {
    const fixture = mount();
    (addDetails(fixture).querySelector('summary') as HTMLElement).click();
    fixture.detectChanges();

    fixture.componentInstance.newBaseUrl.set('https://api.example.test/v1');
    fixture.componentInstance.newApiKey.set('sk-secret');

    fixture.componentInstance.add();

    expect(ai.add).toHaveBeenCalledWith(
      { name: null, baseUrl: 'https://api.example.test/v1', apiKey: 'sk-secret' },
      expect.any(Function),
    );
  });

  it('allows adding with an endpoint and no key, since a local server needs none', () => {
    const fixture = mount();
    (addDetails(fixture).querySelector('summary') as HTMLElement).click();
    fixture.detectChanges();

    expect(fixture.componentInstance.canAdd()).toBe(false);

    fixture.componentInstance.newBaseUrl.set('https://api.example.test/v1');
    fixture.detectChanges();

    expect(fixture.componentInstance.canAdd()).toBe(true);
  });

  it('renders the noStoredKey sentence for an empty hint, and the storedKey sentence otherwise', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, apiKeyHint: '' }), config({ id: 2, apiKeyHint: '1234' })]);
    fixture.detectChanges();
    expandRow(fixture, 0);
    expandRow(fixture, 1);

    expect(row(fixture, 0).querySelector('.hint')?.textContent).toContain(
      'No key is stored — this endpoint is used without one.',
    );
    expect(row(fixture, 1).querySelector('.hint')?.textContent).toContain(
      'A key ending in 1234 is stored.',
    );
  });

  it('changes a model: "change model" calls loadModels, then the picker saves via chooseModel', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1 })]);
    fixture.detectChanges();

    expandRow(fixture, 0);
    (row(fixture, 0).querySelector('.change-model') as HTMLButtonElement).click();
    expect(ai.loadModels).toHaveBeenCalledWith(1);

    ai.choosingModelFor.set(1);
    ai.models.set(['gpt-4o', 'gpt-4o-mini']);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('app-searchable-select')).not.toBeNull();

    fixture.componentInstance.chosenModel.set('gpt-4o');
    fixture.detectChanges();
    (row(fixture, 0).querySelector('.save-model') as HTMLButtonElement).click();

    expect(ai.chooseModel).toHaveBeenCalledWith(1, 'gpt-4o');
  });

  it('resets the picked model whenever a different row starts choosing', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1 }), config({ id: 2 })]);
    ai.choosingModelFor.set(1);
    ai.models.set(['gpt-4o']);
    fixture.detectChanges();
    fixture.componentInstance.chosenModel.set('gpt-4o');

    ai.choosingModelFor.set(2);
    fixture.detectChanges();

    expect(fixture.componentInstance.chosenModel()).toBeNull();
  });

  it('activates a configuration', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, ready: true })]);
    fixture.detectChanges();

    expandRow(fixture, 0);
    (row(fixture, 0).querySelector('.activate') as HTMLButtonElement).click();

    expect(ai.activate).toHaveBeenCalledWith(1);
  });

  it('duplicates a configuration', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 7 })]);
    fixture.detectChanges();

    expandRow(fixture, 0);
    (row(fixture, 0).querySelector('.duplicate') as HTMLButtonElement).click();

    expect(ai.duplicate).toHaveBeenCalledWith(7);
  });

  it('toggles the reasoning preference for a row', () => {
    const fixture = mount();
    const setReasoning = jest.spyOn(ai, 'setReasoning').mockImplementation(() => undefined);
    ai.configs.set([config({ id: 7, suppressReasoning: true })]);
    fixture.detectChanges();

    expandRow(fixture, 0);

    const checkbox: HTMLInputElement =
      fixture.nativeElement.querySelector('.reasoning-toggle input');
    expect(checkbox).not.toBeNull();
    expect(checkbox.checked).toBe(true);
    expect(row(fixture, 0).querySelector('.reasoning-toggle .hint')).toBeNull();

    checkbox.checked = false;
    checkbox.dispatchEvent(new Event('change'));

    expect(setReasoning).toHaveBeenCalledWith(7, false);
  });

  it('toggles the slow-model preference for a row', () => {
    const fixture = mount();
    const setSlowModel = jest.spyOn(ai, 'setSlowModel').mockImplementation(() => undefined);
    ai.configs.set([config({ id: 7, slowModel: false })]);
    fixture.detectChanges();

    expandRow(fixture, 0);

    const checkbox = row(fixture, 0).querySelectorAll(
      '.reasoning-toggle input[type=checkbox]',
    )[1] as HTMLInputElement;
    expect(checkbox).not.toBeUndefined();
    expect(checkbox.checked).toBe(false);

    checkbox.checked = true;
    checkbox.dispatchEvent(new Event('change'));

    expect(setSlowModel).toHaveBeenCalledWith(7, true);
  });

  it('renders the stored batch cap in the number input', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 7, maxBatchSize: 30 })]);
    fixture.detectChanges();

    expandRow(fixture, 0);

    const input = row(fixture, 0).querySelector('input[type="number"]') as HTMLInputElement;
    expect(input).not.toBeNull();
    expect(input.value).toBe('30');
  });

  it('shows the backend default as the placeholder when the batch cap is null', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 7, maxBatchSize: null })]);
    ai.defaultMaxBatchSize.set(140);
    fixture.detectChanges();

    expandRow(fixture, 0);

    const input = row(fixture, 0).querySelector('input[type="number"]') as HTMLInputElement;
    expect(input.value).toBe('');
    expect(input.placeholder).toBe('140');
  });

  it('sends the entered batch cap on change', () => {
    const fixture = mount();
    const setMaxBatchSize = jest.spyOn(ai, 'setMaxBatchSize').mockImplementation(() => undefined);
    ai.configs.set([config({ id: 7, maxBatchSize: null })]);
    fixture.detectChanges();

    expandRow(fixture, 0);

    const input = row(fixture, 0).querySelector('input[type="number"]') as HTMLInputElement;
    input.value = '30';
    input.dispatchEvent(new Event('change'));

    expect(setMaxBatchSize).toHaveBeenCalledWith(7, 30);
  });

  it('sends null, not NaN, when the batch cap field is cleared', () => {
    const fixture = mount();
    const setMaxBatchSize = jest.spyOn(ai, 'setMaxBatchSize').mockImplementation(() => undefined);
    ai.configs.set([config({ id: 7, maxBatchSize: 30 })]);
    fixture.detectChanges();

    expandRow(fixture, 0);

    const input = row(fixture, 0).querySelector('input[type="number"]') as HTMLInputElement;
    input.value = '';
    input.dispatchEvent(new Event('change'));

    expect(setMaxBatchSize).toHaveBeenCalledWith(7, null);
  });

  /**
   * The two per-connection checkboxes are the same shape, so a handler wired
   * to the wrong one would still look right. This pins that each checkbox
   * reports its own state.
   */
  it('keeps the two per-connection checkboxes independent', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 7, suppressReasoning: false, slowModel: true })]);
    fixture.detectChanges();

    expandRow(fixture, 0);

    const boxes = Array.from(
      row(fixture, 0).querySelectorAll('.reasoning-toggle input[type=checkbox]'),
    ) as HTMLInputElement[];
    expect(boxes.map((box) => box.checked)).toEqual([false, true]);
  });

  it('changes the batch concurrency for a row via a 1-8 dropdown', () => {
    const fixture = mount();
    const setBatchConcurrency = jest
      .spyOn(ai, 'setBatchConcurrency')
      .mockImplementation(() => undefined);
    ai.configs.set([config({ id: 7, batchConcurrency: 1 })]);
    fixture.detectChanges();

    expandRow(fixture, 0);

    const select = row(fixture, 0).querySelector('app-field select') as HTMLSelectElement;
    expect(select).not.toBeNull();
    expect(Array.from(select.options).map((option) => option.value)).toEqual([
      '1',
      '2',
      '3',
      '4',
      '5',
      '6',
      '7',
      '8',
    ]);
    expect(select.value).toBe('1');
    const concurrencyTip = row(fixture, 0).querySelector(
      'app-field app-info-tip button.trigger',
    ) as HTMLButtonElement;
    expect(concurrencyTip).not.toBeNull();
    concurrencyTip.click();
    fixture.detectChanges();
    expect(row(fixture, 0).querySelector('app-field .panel')?.textContent).toContain(
      'local model server',
    );

    select.value = '3';
    select.dispatchEvent(new Event('change'));

    expect(setBatchConcurrency).toHaveBeenCalledWith(7, 3);
  });

  it('shows a saved confirmation only on the row that just saved its concurrency', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 7 }), config({ id: 8 })]);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.saved')).toBeNull();

    ai.savedConcurrencyId.set(7);
    fixture.detectChanges();

    expect(row(fixture, 0).querySelector('.saved')).not.toBeNull();
    expect(row(fixture, 1).querySelector('.saved')).toBeNull();
  });

  it('disables activation for a row that is already active, not ready, or while busy', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, active: true, ready: true }), config({ id: 2, ready: false })]);
    // A ready active connection folds the provider group to its summary, so the
    // per-connection rows only exist once the manager is opened (#541).
    fixture.componentInstance.managing.set(true);
    fixture.detectChanges();

    // Row 1 is the active, ready configuration, so it is the one the
    // recommendation card now renders for — same as the dedicated card test
    // below.
    http.expectOne('/api/me/ai/recommendations').flush(RECOMMENDATIONS);
    http.expectOne('/api/recommendations/runs/debug-log').flush({ entries: [] });
    // `tz` is a query param now, so an exact-string match no longer finds the
    // request -- `req.url` excludes the query string, unlike `urlWithParams`.
    http
      .expectOne((req) => req.url === '/api/recommendations/runs/history')
      .flush({ totalCostNanoCredits: null, months: [], latest: null });

    (row(fixture, 0).querySelector('summary') as HTMLElement).click();
    (row(fixture, 1).querySelector('summary') as HTMLElement).click();
    fixture.detectChanges();

    expect((row(fixture, 0).querySelector('.activate button') as HTMLButtonElement).disabled).toBe(
      true,
    );
    expect((row(fixture, 1).querySelector('.activate button') as HTMLButtonElement).disabled).toBe(
      true,
    );
  });

  it('renames a configuration', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, name: 'Old name' })]);
    fixture.detectChanges();

    expandRow(fixture, 0);
    (row(fixture, 0).querySelector('.rename') as HTMLButtonElement).click();
    fixture.detectChanges();

    const input = row(fixture, 0).querySelector('input[type="text"]') as HTMLInputElement;
    input.value = 'New name';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    (row(fixture, 0).querySelector('.rename-save') as HTMLButtonElement).click();

    expect(ai.rename).toHaveBeenCalledWith(1, 'New name');
  });

  it('sends a null name when renaming to a blank value', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, name: 'Old name' })]);
    fixture.detectChanges();

    expandRow(fixture, 0);
    (row(fixture, 0).querySelector('.rename') as HTMLButtonElement).click();
    fixture.detectChanges();

    const input = row(fixture, 0).querySelector('input[type="text"]') as HTMLInputElement;
    input.value = '   ';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    (row(fixture, 0).querySelector('.rename-save') as HTMLButtonElement).click();

    expect(ai.rename).toHaveBeenCalledWith(1, null);
  });

  it('cancels the rename without calling rename()', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, name: 'Old name' })]);
    fixture.detectChanges();

    expandRow(fixture, 0);
    (row(fixture, 0).querySelector('.rename') as HTMLButtonElement).click();
    fixture.detectChanges();
    (row(fixture, 0).querySelector('.rename-cancel') as HTMLButtonElement).click();
    fixture.detectChanges();

    expect(ai.rename).not.toHaveBeenCalled();
    expect(row(fixture, 0).querySelector('.label')?.textContent).toContain('Old name');
  });

  it('opens the confirm dialog and removes the configuration on a truthy close', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1 })]);
    fixture.detectChanges();
    dialogStub.open.mockReturnValue({ closed: of(true) });

    expandRow(fixture, 0);
    (row(fixture, 0).querySelector('.delete') as HTMLButtonElement).click();

    expect(dialogStub.open).toHaveBeenCalled();
    expect(ai.remove).toHaveBeenCalledWith(1);
  });

  it('opens the delete dialog with the configuration-specific title, message and danger styling', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1 })]);
    fixture.detectChanges();
    dialogStub.open.mockReturnValue({ closed: of(false) });

    expandRow(fixture, 0);
    (row(fixture, 0).querySelector('.delete') as HTMLButtonElement).click();

    const [, dialogConfig] = dialogStub.open.mock.calls.at(-1) as [unknown, { data: ConfirmData }];
    expect(dialogConfig.data.title).toBe('Delete this configuration?');
    expect(dialogConfig.data.message).toBe(
      'This deletes the endpoint, the stored key and the model. AI features stop if this was the active configuration.',
    );
    expect(dialogConfig.data.danger).toBe(true);
  });

  it('does nothing when the delete dialog is dismissed', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1 })]);
    fixture.detectChanges();
    dialogStub.open.mockReturnValue({ closed: of(false) });

    expandRow(fixture, 0);
    (row(fixture, 0).querySelector('.delete') as HTMLButtonElement).click();

    expect(ai.remove).not.toHaveBeenCalled();
  });

  it('shows a failed add under the add form, not above the configs list', () => {
    const fixture = mountWithConfigs([CONFIG]);

    ai.failure.set(
      scoped(
        {
          kind: 'validation',
          detail: 'One or more fields are invalid.',
          fieldErrors: [{ field: 'apiKey', messages: ['This value is too short.'] }],
        },
        { action: 'add' },
      ),
    );
    fixture.detectChanges();

    expect(banners(addCard(fixture))).toEqual(['API key: This value is too short.']);
    expect(banners(listCard(fixture))).toEqual([]);
  });

  it('shows a failed row write inside that row', () => {
    const fixture = mountWithConfigs([config({ id: 7 }), config({ id: 8 })]);
    expandRow(fixture, 1);

    ai.failure.set(
      scoped(
        { kind: 'provider', detail: 'That address did not answer.', fieldErrors: [] },
        { action: 'row', configId: 8 },
      ),
    );
    fixture.detectChanges();

    expect(banners(row(fixture, 1))).toEqual(['That address did not answer.']);
    expect(banners(row(fixture, 0))).toEqual([]);
    expect(banners(addCard(fixture))).toEqual([]);
  });

  it('shows a failed list load on the list card', () => {
    const fixture = mountWithConfigs([CONFIG]);

    ai.failure.set(scoped({ kind: 'unknown', detail: null, fieldErrors: [] }, { action: 'load' }));
    fixture.detectChanges();

    expect(banners(listCard(fixture))).toEqual(['Something went wrong. Try again.']);
  });

  it('keeps the translated message for the kinds whose next move is not "retry"', () => {
    const fixture = mountWithConfigs([CONFIG]);

    ai.failure.set(
      scoped(
        { kind: 'limit', detail: 'This account already holds the maximum.', fieldErrors: [] },
        { action: 'add' },
      ),
    );
    fixture.detectChanges();

    expect(banners(addCard(fixture))).toEqual([
      'You have reached the maximum number of saved configurations.',
    ]);
  });

  it('names an unrecognised field by its raw path rather than dropping it', () => {
    const fixture = mountWithConfigs([CONFIG]);

    ai.failure.set(
      scoped(
        {
          kind: 'validation',
          detail: null,
          fieldErrors: [{ field: 'somethingNew', messages: ['Nope.'] }],
        },
        { action: 'add' },
      ),
    );
    fixture.detectChanges();

    expect(banners(addCard(fixture))).toEqual(['somethingNew: Nope.']);
  });

  it('collapses a row by default', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1 })]);
    fixture.detectChanges();

    const details = row(fixture, 0).querySelector('details') as HTMLDetailsElement;
    expect(details.open).toBe(false);
  });

  it('carries the label and active badge on the collapsed summary', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, name: 'My provider', active: true }), config({ id: 2 })]);
    fixture.detectChanges();

    const activeSummary = row(fixture, 0).querySelector('summary') as HTMLElement;
    expect(activeSummary.querySelector('.label')?.textContent).toContain('My provider');
    expect(activeSummary.querySelector('.badge')).not.toBeNull();

    const inactiveSummary = row(fixture, 1).querySelector('summary') as HTMLElement;
    expect(inactiveSummary.querySelector('.badge')).toBeNull();
  });

  it('marks only the active row with is-active', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, active: true }), config({ id: 2 })]);
    fixture.detectChanges();

    expect(row(fixture, 0).classList.contains('is-active')).toBe(true);
    expect(row(fixture, 1).classList.contains('is-active')).toBe(false);
  });

  it('reveals the action bar with all five buttons once the row is expanded', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, ready: true })]);
    fixture.detectChanges();

    expandRow(fixture, 0);

    const acts = row(fixture, 0).querySelector('.acts') as HTMLElement;
    expect(acts).not.toBeNull();
    expect(acts.querySelector('.activate')).not.toBeNull();
    expect(acts.querySelector('.change-model')).not.toBeNull();
    expect(acts.querySelector('.duplicate')).not.toBeNull();
    expect(acts.querySelector('.rename')).not.toBeNull();
    expect(acts.querySelector('.delete')).not.toBeNull();
  });

  it('puts the add-configuration form in its own drill-in, separate from the configs list', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1 })]);
    fixture.detectChanges();

    const listRegion = fixture.nativeElement.querySelector('.conn-list') as HTMLElement;
    const addForm = fixture.nativeElement.querySelector('.add-group') as HTMLElement;
    expect(listRegion.querySelector('.add-config')).toBeNull();
    expect(addForm.querySelector('.configs')).toBeNull();

    // The add form lives inside its own collapsed drill-in labelled "Add a
    // configuration"; the configs list is not inside that same drill-in.
    const addDrillIn = addDetails(fixture);
    expect(addDrillIn.querySelector('summary')?.textContent).toContain('Add a configuration');
    expect(addDrillIn.querySelector('.configs')).toBeNull();
  });

  it('collapses the add-configuration card by default', () => {
    const fixture = mount();

    const details = addDetails(fixture);
    expect(details.open).toBe(false);
    expect(details.querySelector('summary')?.textContent).toContain('Add a configuration');
  });

  it('shows the recommendation settings card once the active config is ready, and not before', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, active: true, ready: false })]);
    ai.activeId.set(1);
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('app-recommendation-settings-card')).toBeNull();

    ai.configs.set([config({ id: 1, active: true, ready: true, model: 'gpt-4o' })]);
    fixture.detectChanges();

    http.expectOne('/api/me/ai/recommendations').flush(RECOMMENDATIONS);
    http.expectOne('/api/recommendations/runs/debug-log').flush({ entries: [] });
    // `tz` is a query param now, so an exact-string match no longer finds the
    // request -- `req.url` excludes the query string, unlike `urlWithParams`.
    http
      .expectOne((req) => req.url === '/api/recommendations/runs/history')
      .flush({ totalCostNanoCredits: null, months: [], latest: null });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('app-recommendation-settings-card')).not.toBeNull();
  });

  // The recommendation card, run history and debug log all fetch on
  // construction once the active config is ready; a ready-state test must drain
  // those three requests before `http.verify()`.
  const flushReady = (): void => {
    http.expectOne('/api/me/ai/recommendations').flush(RECOMMENDATIONS);
    http.expectOne('/api/recommendations/runs/debug-log').flush({ entries: [] });
    http
      .expectOne((req) => req.url === '/api/recommendations/runs/history')
      .flush({ totalCostNanoCredits: null, months: [], latest: null });
  };

  it('folds the provider group to a one-line summary when a ready active connection exists', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, name: 'OpenAI', active: true, ready: true, model: 'gpt-4o' })]);
    fixture.detectChanges();
    flushReady();
    fixture.detectChanges();

    const summary = fixture.nativeElement.querySelector('.provider-summary') as HTMLElement;
    expect(summary).not.toBeNull();
    expect(summary.textContent).toContain('OpenAI');
    expect(summary.textContent).toContain('gpt-4o');
    expect(summary.textContent).toContain('connected');
    // The manager, and every per-connection row, stays hidden while folded.
    expect(fixture.nativeElement.querySelector('.config-row')).toBeNull();
    expect(fixture.nativeElement.querySelector('.add-group')).toBeNull();
  });

  it('opens the connection manager on Manage and folds back on Done', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, active: true, ready: true, model: 'gpt-4o' })]);
    fixture.detectChanges();
    flushReady();
    fixture.detectChanges();

    (fixture.nativeElement.querySelector('.manage') as HTMLElement).click();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.config-row')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('.provider-summary')).toBeNull();

    (fixture.nativeElement.querySelector('.manage-done') as HTMLElement).click();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('.provider-summary')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('.config-row')).toBeNull();
  });

  it('shows the connection manager, not a summary, when no ready active connection exists', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, active: false, ready: false })]);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('.provider-summary')).toBeNull();
    expect(fixture.nativeElement.querySelector('.add-group')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('.config-row')).not.toBeNull();
  });

  it('gives the add-form fields info tips and keeps the short hints', () => {
    const fixture = mountWithConfigs([]);

    const addGroup = fixture.nativeElement.querySelector('.add-group') as HTMLElement;
    const triggers = Array.from(
      addGroup.querySelectorAll('app-info-tip button.trigger'),
    ) as HTMLButtonElement[];
    expect(triggers.map((el) => el.getAttribute('aria-label'))).toEqual([
      'Optional name',
      'Endpoint',
      'API key',
    ]);

    const hints = Array.from(addGroup.querySelectorAll('app-field .hint')).map((el) =>
      el.textContent?.trim(),
    );
    expect(hints).toEqual([
      'The full API root, including any version path — for example https://api.openai.com/v1',
      'Leave it empty if the provider needs no key, such as a local model server. The key is sent once and stored encrypted. Enter it again to replace it.',
    ]);
  });

  it('renders the setup guide as a collapsed drill-in inside the provider group', () => {
    const fixture = mountWithConfigs([]);

    const guideDetails = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('details'),
    ).find((details) => details.querySelector('.guide')) as HTMLDetailsElement;
    expect(guideDetails).not.toBeUndefined();
    expect(guideDetails.querySelector('summary')?.textContent).toContain('Step-by-step setup');
    expect(guideDetails.open).toBe(false);

    const steps = guideDetails.querySelectorAll('.guide ol li');
    expect(steps.length).toBe(10);
  });

  it('explains the row actions with one tip and each connection checkbox with its own', () => {
    const fixture = mountWithConfigs([CONFIG]);

    const body = fixture.nativeElement.querySelector('.config-body') as HTMLElement;
    const actionsTrigger = body.querySelector('.acts-info button.trigger') as HTMLButtonElement;
    expect(actionsTrigger.getAttribute('aria-label')).toBe('What these buttons do');

    actionsTrigger.click();
    fixture.detectChanges();
    const actionsPanel = body.querySelector('.acts-info .panel')?.textContent ?? '';
    expect(actionsPanel).toContain('makes this configuration the active one');
    expect(actionsPanel).toContain('copies the configuration together with its stored key');
    expect(actionsPanel).toContain('removes the endpoint, the stored key and the model');

    const toggleTriggers = Array.from(
      body.querySelectorAll('.reasoning-toggle app-info-tip button.trigger'),
    ) as HTMLButtonElement[];
    expect(toggleTriggers.map((trigger) => trigger.getAttribute('aria-label'))).toEqual([
      'Ask the model not to reason',
      'Slow or local model',
      'Maximum batch size',
    ]);
    expect(body.querySelector('.reasoning-toggle .hint')).toBeNull();
  });
});
