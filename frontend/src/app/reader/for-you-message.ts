// src/app/reader/for-you-message.ts
import { RecommendationFailure } from './recommendations.service';

/**
 * The translation key that explains a failed for-you run to the user.
 *
 * Each cause needs its own sentence: another run holding the lock past our
 * patience, the backend giving up on the run itself, or the request failing
 * outright. Without this the run button just reappears with no explanation
 * (#308 final review, Important 1).
 *
 * A server problem keeps the general wording rather than surfacing
 * `problem.detail`: that text comes from the backend in English only, and the
 * banner is a translated surface.
 */
export function forYouFailureKey(failure: RecommendationFailure): string {
  switch (failure.kind) {
    case 'busy':
      return 'reader.forYouBusy';
    case 'failed':
      return 'reader.forYouFailed';
    case 'http':
      return 'reader.forYouUnreachable';
  }
}
