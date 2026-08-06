import { TestBed } from '@angular/core/testing';
import { AiAvailabilityService, AiState } from './ai-availability.service';
import { CurrentUser } from './auth.service';

describe('AiAvailabilityService', () => {
  function service(): AiAvailabilityService {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});
    return TestBed.inject(AiAvailabilityService);
  }

  const user = (ready: boolean, model: string | null): CurrentUser =>
    ({ ai: { ready, model } }) as CurrentUser;

  const state = (over: Partial<AiState>): AiState => ({
    configured: true,
    baseUrl: 'https://api.example.test/v1',
    apiKeyHint: '1234',
    model: null,
    ready: false,
    ...over,
  });

  it('is not ready before an account is adopted', () => {
    expect(service().ready()).toBe(false);
  });

  it('adopts the account profile', () => {
    const ai = service();
    ai.adopt(user(true, 'gpt-4o'));
    expect(ai.ready()).toBe(true);
    expect(ai.model()).toBe('gpt-4o');
  });

  it('applies a saved settings state without another profile fetch', () => {
    const ai = service();
    ai.apply(state({ model: 'gpt-4o-mini', ready: true }));
    expect(ai.ready()).toBe(true);
    expect(ai.model()).toBe('gpt-4o-mini');
  });

  it('drops the signed-out account state', () => {
    const ai = service();
    ai.adopt(user(true, 'gpt-4o'));
    ai.reset();
    expect(ai.ready()).toBe(false);
    expect(ai.model()).toBeNull();
  });
});
