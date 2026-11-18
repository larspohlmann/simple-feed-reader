import { Component, computed, inject, input } from '@angular/core';
import { SubscriptionTagDto } from '../../models';
import { BlockCard, MagazineBlock } from './magazine-block';
import { EntryHeroComponent } from './blocks/entry-hero/entry-hero.component';
import { EntryCompactComponent } from './blocks/entry-compact/entry-compact.component';
import { EntrySplitComponent } from './blocks/entry-split/entry-split.component';
import { EntryWideComponent } from './blocks/entry-wide/entry-wide.component';
import { EntryThumbComponent } from './blocks/entry-thumb/entry-thumb.component';
import { EntryQuoteComponent } from './blocks/entry-quote/entry-quote.component';
import { EntryKickerComponent } from './blocks/entry-kicker/entry-kicker.component';
import { EntryLinkComponent } from './blocks/entry-link/entry-link.component';
import { EntryThreadComponent } from './blocks/entry-thread/entry-thread.component';
import { EntryMediaComponent } from './blocks/entry-media/entry-media.component';
import { EntryQuotingComponent } from './blocks/entry-quoting/entry-quoting.component';
import { STORY_CONTEXT } from '../../trending/story-context';
import { TrendingStoryDto } from '../../trending/trending.models';
import { StoryTitleComponent } from '../../trending/story-title/story-title.component';
import { SourceGroupComponent } from './source-group.component';

// One shared instance: a fresh `[]` per check would change every tag-less block's
// input identity on every tick and re-render it, defeating OnPush (#501).
export const NO_TAGS: SubscriptionTagDto[] = [];

/** One magazine block, rendered by its kind. */
@Component({
  selector: 'app-magazine-block',
  imports: [
    EntryHeroComponent,
    EntryCompactComponent,
    EntrySplitComponent,
    EntryWideComponent,
    EntryThumbComponent,
    EntryQuoteComponent,
    EntryKickerComponent,
    EntryLinkComponent,
    EntryThreadComponent,
    EntryMediaComponent,
    EntryQuotingComponent,
    SourceGroupComponent,
    StoryTitleComponent,
  ],
  templateUrl: './magazine-block.component.html',
  styleUrl: './magazine-block.component.scss',
  providers: [{ provide: STORY_CONTEXT, useFactory: () => inject(MagazineBlockComponent).story }],
})
export class MagazineBlockComponent {
  readonly block = input.required<MagazineBlock>();
  /** Feed tags keyed by subscription id, used to render each entry's tag pills. */
  readonly feedTags = input<Map<number, SubscriptionTagDto[]>>(new Map());

  readonly story = computed<TrendingStoryDto | null>(() => {
    const block = this.block();
    return block.kind === 'group' ? null : block.story;
  });

  protected tagsFor(subscriptionId: number): SubscriptionTagDto[] {
    return this.feedTags().get(subscriptionId) ?? NO_TAGS;
  }

  protected cardOf(block: MagazineBlock): BlockCard {
    return block as Exclude<MagazineBlock, { kind: 'group' }>;
  }

  protected side(block: MagazineBlock): 'left' | 'right' {
    return block.kind === 'split' ? block.imageSide : 'right';
  }

  protected group(block: MagazineBlock): Extract<MagazineBlock, { kind: 'group' }> {
    return block as Extract<MagazineBlock, { kind: 'group' }>;
  }
}
