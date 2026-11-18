import { Injectable, effect, inject, linkedSignal, untracked } from '@angular/core';
import { queryFromSelection } from '../../query/entry-query';
import { SelectionOf } from '../../query/query';
import { EntriesStore } from '../../state/entries.store';
import { ListContext } from '../../state/list-context';
import { StoryOverlay } from '../story-overlay.service';

const GONE = 'trending_story_not_found';

/** A Trending story's list over the route's (#2329), loaded for the story the overlay names. Its rows are the
 *  shared cache's, so a change to one shows in the list beneath too, which keeps its rows rather than reloading. */
@Injectable()
export class StoryListContext extends ListContext {
  private readonly overlay = inject(StoryOverlay);
  readonly entries = new EntriesStore();
  /** The open story, held while the layer slides out after the overlay has closed. */
  readonly selection = linkedSignal<
    SelectionOf<'trending-story'> | null,
    SelectionOf<'trending-story'>
  >({
    source: this.overlay.selection,
    computation: (open, previous) => open ?? previous!.value,
  }).asReadonly();

  constructor() {
    super();
    effect(() => {
      const query = queryFromSelection(this.selection());
      untracked(() => {
        this.clearLeaving();
        this.entries.load(query);
      });
    });
    effect(() => {
      if (this.entries.error()?.type !== GONE) return;
      untracked(() => {
        this.entries.dismissError();
        this.overlay.leaveStoryThatIsGone();
      });
    });
  }
}
