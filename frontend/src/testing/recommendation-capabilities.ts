import { RecommendationCapabilities } from '../app/core/ai-availability.service';

/** What an engine that offers everything reports: reasons, and every tuning field. */
export const EVERY_RECOMMENDATION_CAPABILITY: RecommendationCapabilities = {
  reasons: true,
  tuningFields: [
    'contextWindow',
    'batchSize',
    'suppressReasoning',
    'slowModel',
    'maxBatchSize',
    'batchConcurrency',
  ],
};
