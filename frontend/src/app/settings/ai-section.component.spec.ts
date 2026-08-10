// src/app/settings/ai-section.component.spec.ts
import { Dialog } from '@angular/cdk/dialog';
import { WritableSignal, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { API_BASE_URL } from '../core/api';
import { ConfirmData } from '../shared/confirm-dialog/confirm-dialog.component';
import { AiFailure } from './ai-failure';
import { AiSectionComponent } from './ai-section.component';
import { AiConfig, AiSettingsService } from './ai-settings.service';

interface AiSettingsStub {
  configs: WritableSignal<readonly AiConfig[]>;
  activeId: WritableSignal<number | null>;
  models: WritableSignal<readonly string[]>;
  choosingModelFor: WritableSignal<number | null>;
  busy: WritableSignal<boolean>;
  failure: WritableSignal<AiFailure | null>;
  savedConcurrencyId: WritableSignal<number | null>;
  load: jest.Mock;
  add: jest.Mock;
  loadModels: jest.Mock;
  chooseModel: jest.Mock;
  duplicate: jest.Mock;
  rename: jest.Mock;
  setReasoning: jest.Mock;
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
  ...over,
});

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

function createStub(): AiSettingsStub {
  return {
    configs: signal<readonly AiConfig[]>([]),
    activeId: signal<number | null>(null),
    models: signal<readonly string[]>([]),
    choosingModelFor: signal<number | null>(null),
    busy: signal(false),
    failure: signal<AiFailure | null>(null),
    savedConcurrencyId: signal<number | null>(null),
    load: jest.fn(),
    add: jest.fn(),
    loadModels: jest.fn(),
    chooseModel: jest.fn(),
    duplicate: jest.fn(),
    rename: jest.fn(),
    setReasoning: jest.fn(),
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

  it('adds a configuration and clears the typed key', () => {
    const fixture = mount();
    fixture.componentInstance.newName.set('My provider');
    fixture.componentInstance.newBaseUrl.set('https://api.example.test/v1');
    fixture.componentInstance.newApiKey.set('sk-secret');

    fixture.componentInstance.add();

    expect(ai.add).toHaveBeenCalledWith('My provider', 'https://api.example.test/v1', 'sk-secret');
    expect(fixture.componentInstance.newApiKey()).toBe('');
  });

  it('sends no name when the optional field is left blank', () => {
    const fixture = mount();
    fixture.componentInstance.newBaseUrl.set('https://api.example.test/v1');
    fixture.componentInstance.newApiKey.set('sk-secret');

    fixture.componentInstance.add();

    expect(ai.add).toHaveBeenCalledWith(null, 'https://api.example.test/v1', 'sk-secret');
  });

  it('changes a model: "change model" calls loadModels, then the picker saves via chooseModel', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1 })]);
    fixture.detectChanges();

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

    (row(fixture, 0).querySelector('.activate') as HTMLButtonElement).click();

    expect(ai.activate).toHaveBeenCalledWith(1);
  });

  it('duplicates a configuration', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 7 })]);
    fixture.detectChanges();

    (row(fixture, 0).querySelector('.duplicate') as HTMLButtonElement).click();

    expect(ai.duplicate).toHaveBeenCalledWith(7);
  });

  it('toggles the reasoning preference for a row', () => {
    const fixture = mount();
    const setReasoning = jest.spyOn(ai, 'setReasoning').mockImplementation(() => undefined);
    ai.configs.set([config({ id: 7, suppressReasoning: true })]);
    fixture.detectChanges();

    const checkbox: HTMLInputElement =
      fixture.nativeElement.querySelector('.reasoning-toggle input');
    expect(checkbox).not.toBeNull();
    expect(checkbox.checked).toBe(true);
    expect(row(fixture, 0).querySelector('.reasoning-toggle .hint')?.textContent).toContain(
      'rejects the request',
    );

    checkbox.checked = false;
    checkbox.dispatchEvent(new Event('change'));

    expect(setReasoning).toHaveBeenCalledWith(7, false);
  });

  it('changes the batch concurrency for a row via a 1-4 dropdown', () => {
    const fixture = mount();
    const setBatchConcurrency = jest
      .spyOn(ai, 'setBatchConcurrency')
      .mockImplementation(() => undefined);
    ai.configs.set([config({ id: 7, batchConcurrency: 1 })]);
    fixture.detectChanges();

    const select = row(fixture, 0).querySelector('app-field select') as HTMLSelectElement;
    expect(select).not.toBeNull();
    expect(Array.from(select.options).map((option) => option.value)).toEqual(['1', '2', '3', '4']);
    expect(select.value).toBe('1');
    expect(row(fixture, 0).querySelector('app-field .hint')?.textContent).toContain('local model');

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
    fixture.detectChanges();

    // Row 1 is the active, ready configuration, so it is the one the
    // recommendation card now renders for — same as the dedicated card test
    // below.
    http.expectOne('/api/me/ai/recommendations').flush(RECOMMENDATIONS);
    http.expectOne('/api/recommendations/runs/debug-log').flush({ entries: [] });

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

    (row(fixture, 0).querySelector('.delete') as HTMLButtonElement).click();

    expect(dialogStub.open).toHaveBeenCalled();
    expect(ai.remove).toHaveBeenCalledWith(1);
  });

  it('opens the delete dialog with the configuration-specific title, message and danger styling', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1 })]);
    fixture.detectChanges();
    dialogStub.open.mockReturnValue({ closed: of(false) });

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

    (row(fixture, 0).querySelector('.delete') as HTMLButtonElement).click();

    expect(ai.remove).not.toHaveBeenCalled();
  });

  it('shows the server sentence for a provider refusal, and a translated message otherwise', () => {
    const fixture = mount();
    ai.failure.set({ kind: 'provider', detail: 'That endpoint refused the key.' });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('app-error-banner')?.textContent).toContain(
      'refused the key',
    );

    ai.failure.set({ kind: 'limit', detail: null });
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('app-error-banner')?.textContent).toContain(
      'maximum number',
    );
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
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('app-recommendation-settings-card')).not.toBeNull();
  });
});
