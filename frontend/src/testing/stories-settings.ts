import { StoriesSettingsState } from '../app/settings/stories/stories-settings.service';

/** A story settings state with titles on and the active connection writing them. */
export function storiesState(over: Partial<StoriesSettingsState> = {}): StoriesSettingsState {
  return {
    titlesEnabled: true,
    writingConnectionId: null,
    writingConnection: {
      id: 3,
      name: 'Home LLM',
      baseUrl: 'https://llm.example.test/v1',
      model: 'qwen3-14b',
    },
    writingCandidates: [
      { id: 3, name: 'Home LLM', baseUrl: 'https://llm.example.test/v1', model: 'qwen3-14b' },
      { id: 7, name: null, baseUrl: 'https://api.openai.com/v1', model: 'gpt-4o' },
    ],
    ...over,
  };
}
