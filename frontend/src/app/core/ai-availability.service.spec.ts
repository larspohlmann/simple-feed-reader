import { TestBed } from '@angular/core/testing';
import { EVERY_RECOMMENDATION_CAPABILITY } from '../../testing/recommendation-capabilities';
import {
  AiAvailability,
  AiAvailabilityService,
  NO_RECOMMENDATION_CAPABILITIES,
  RecommendationCapabilities,
} from './ai-availability.service';
import { CurrentUser } from './auth/auth.service';

describe('AiAvailabilityService', () => {
  function service(): AiAvailabilityService {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({});
    return TestBed.inject(AiAvailabilityService);
  }

  const user = (
    ready: boolean,
    model: string | null,
    capabilities: RecommendationCapabilities | null = null,
  ): CurrentUser => ({ ai: { ready, model, capabilities } }) as CurrentUser;

  const availability = (over: Partial<AiAvailability>): AiAvailability => ({
    model: null,
    ready: false,
    capabilities: null,
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
    ai.apply(availability({ model: 'gpt-4o-mini', ready: true }));
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

  it('offers no recommendation capability before an account is adopted', () => {
    expect(service().capabilities()).toEqual(NO_RECOMMENDATION_CAPABILITIES);
  });

  it("adopts the active connection's capabilities", () => {
    const ai = service();
    ai.adopt(user(true, 'gpt-4o', { reasons: true, tuningFields: ['slowModel'] }));
    expect(ai.capabilities()).toEqual({ reasons: true, tuningFields: ['slowModel'] });
  });

  it('drops the capabilities with the signed-out account', () => {
    const ai = service();
    ai.adopt(user(true, 'gpt-4o', EVERY_RECOMMENDATION_CAPABILITY));
    ai.reset();
    expect(ai.capabilities()).toEqual(NO_RECOMMENDATION_CAPABILITIES);
  });
});
