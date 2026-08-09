import { Component, input } from '@angular/core';
import { EntryDto } from '../models';

/** Wraps one entry's card and, only for a for-you result, renders the
 *  recommender's reason beneath it — plus the 0-100 score when the user's
 *  debug setting is on and the backend therefore sent it. The card is
 *  projected, so no card component knows about recommendations. The input is
 *  nullable so the magazine layout can wrap group blocks, which carry no single
 *  entry, with the same wrapper. */
@Component({
  selector: 'app-recommendation-strip',
  templateUrl: './recommendation-strip.component.html',
  styleUrl: './recommendation-strip.component.scss',
})
export class RecommendationStripComponent {
  readonly entry = input<EntryDto | null>(null);
}
