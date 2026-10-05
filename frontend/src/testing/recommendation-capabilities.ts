import {
  RECOMMENDATION_TUNING_FIELDS,
  RecommendationCapabilities,
} from '../app/core/ai-availability.service';

/** What an engine that offers everything reports: reasons, a prompt, and every tuning field. */
export const EVERY_RECOMMENDATION_CAPABILITY: RecommendationCapabilities = {
  reasons: true,
  prompt: true,
  profile: 'own',
  tuningFields: RECOMMENDATION_TUNING_FIELDS,
};

/** What a scoring model reports: no reasons, no prompt, a borrowed profile, only parallel requests to tune. */
export const SCORING_RECOMMENDATION_CAPABILITIES: RecommendationCapabilities = {
  reasons: false,
  prompt: false,
  profile: 'borrowed',
  tuningFields: ['batchConcurrency'],
};
