import {
  RecommendationExpertDefaults,
  RecommendationSettingsState,
} from './recommendation-settings.service';

export function typedSeed(state: RecommendationSettingsState | null): RecommendationExpertDefaults {
  const pick = <Key extends keyof RecommendationSettingsState, Fallback>(
    key: Key,
    fallback: Fallback,
  ): NonNullable<RecommendationSettingsState[Key]> | Fallback => state?.[key] ?? fallback;
  return {
    guidancePrompt: pick('guidancePrompt', ''),
    favoritesCap: pick('favoritesCap', 0),
    candidatePoolSize: pick('candidatePoolSize', 0),
    picksLimit: pick('picksLimit', 0),
    batchSize: pick('batchSize', 'medium' as const),
    contextWindow: pick('contextWindowOverride', null),
  };
}
