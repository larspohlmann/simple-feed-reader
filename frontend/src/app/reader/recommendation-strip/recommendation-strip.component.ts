import { Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { EntryDto } from '../models';

/** Wraps one entry's card and, only for a for-you result, renders the
 *  recommender's reason and score beneath it — one explanation, shown together
 *  on the reader's "show why" setting (#576, superseding #541's split). Each
 *  part keeps its own condition since they can still be individually blank: a
 *  pre-#403 row carries a null score, and the salvager stores '' for a blank
 *  reason. The model scores 0-1000 (#403) for finer separation; the strip
 *  shows it out of 100.
 *
 *  The card is projected, so no card component knows about recommendations.
 *  The input is nullable so the magazine layout can wrap group blocks (no
 *  single entry) with the same wrapper. */
@Component({
  selector: 'app-recommendation-strip',
  imports: [TranslocoPipe],
  templateUrl: './recommendation-strip.component.html',
  styleUrl: './recommendation-strip.component.scss',
})
export class RecommendationStripComponent {
  readonly entry = input<EntryDto | null>(null);

  /** The stored 0-1000 score as a figure out of 100. Rows written before #403
   *  hold a 0-100 score and read a tenth of their true value — those runs have
   *  long since scrolled away, and no list mixes the two scales. */
  displayScore(score: number): number {
    return Math.round(score / 10);
  }
}
