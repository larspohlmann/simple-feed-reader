import {
  RECOMMENDATION_TUNING_FIELDS,
  RecommendationCapabilities,
} from '../app/core/ai-availability.service';

/** What an engine that offers everything reports: reasons, a prompt, and every tuning field. */
export const EVERY_RECOMMENDATION_CAPABILITY: RecommendationCapabilities = {
  reasons: true,
  prompt: true,
  tuningFields: RECOMMENDATION_TUNING_FIELDS,
};
