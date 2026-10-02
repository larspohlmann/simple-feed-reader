import { RecommendationCapabilities } from '../app/core/ai-availability.service';

/** What the LLM engine reports: reasons, and every tuning field. */
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
