import { Component, input } from '@angular/core';
import { EntryDto } from '../models';

/** Wraps one entry's card and, only for a for-you result, renders the
 *  recommender's reason beneath it — plus the score when the user's debug
 *  setting is on and the backend therefore sent it. The model scores on
 *  0-1000 (#403), which is room for it to separate candidates rather than
 *  stack them on one round number; a reader does not need that resolution,
 *  so the strip shows it out of 100.
 *
 *  The card is projected, so no card component knows about recommendations.
 *  The input is nullable so the magazine layout can wrap group blocks, which
 *  carry no single entry, with the same wrapper. */
@Component({
  selector: 'app-recommendation-strip',
  templateUrl: './recommendation-strip.component.html',
  styleUrl: './recommendation-strip.component.scss',
})
export class RecommendationStripComponent {
  readonly entry = input<EntryDto | null>(null);

  /** The stored 0-1000 score as a figure out of 100. Rows written before #403
   *  hold a 0-100 score and so read a tenth of their true value here; they
   *  belong to runs that have long since scrolled away, and no list ever mixes
   *  the two scales. */
  displayScore(score: number): number {
    return Math.round(score / 10);
  }
}
