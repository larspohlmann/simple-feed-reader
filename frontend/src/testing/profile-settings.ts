import { ProfileRun, ProfileSettingsState } from '../app/settings/profile/profile-settings.service';

/** A profile section state with a stored profile, the active connection building it and debug off. */
export function profileState(over: Partial<ProfileSettingsState> = {}): ProfileSettingsState {
  return {
    profileText: 'Likes maps and rail history.',
    generatedAt: '2026-10-03T07:15:00+00:00',
    generatedBy: { providerHost: 'llm.example.test', model: 'qwen3-14b' },
    intervalHours: 24,
    intervalChoices: [null, 6, 12, 24, 48, 168],
    connectionId: null,
    connection: {
      id: 3,
      name: 'Home LLM',
      baseUrl: 'https://llm.example.test/v1',
      model: 'qwen3-14b',
    },
    candidates: [
      { id: 3, name: 'Home LLM', baseUrl: 'https://llm.example.test/v1', model: 'qwen3-14b' },
      { id: 7, name: null, baseUrl: 'https://api.openai.com/v1', model: 'gpt-4o' },
    ],
    keptCap: 40,
    viewedCap: 80,
    defaults: { keptCap: 40, viewedCap: 80 },
    bounds: { keptCap: { min: 0, max: 500 }, viewedCap: { min: 0, max: 500 } },
    debugEnabled: false,
    ...over,
  };
}

/** The run status of an account that has never run a profile run, unless overridden. */
export function profileRun(over: Partial<ProfileRun> = {}): ProfileRun {
  return {
    status: 'none',
    id: null,
    trigger: null,
    outcome: null,
    error: null,
    createdAt: null,
    completedAt: null,
    providerHost: null,
    model: null,
    attempts: 0,
    maxAttempts: 3,
    transportFailures: 0,
    maxTransportFailures: 3,
    streamedChars: 0,
    ...over,
  };
}
