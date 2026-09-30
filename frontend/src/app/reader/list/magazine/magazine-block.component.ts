import { Component, input } from '@angular/core';
import { EntryDto, SubscriptionTagDto } from '../../models';
import { MagazineBlock } from './magazine-block';
import { EntryHeroComponent } from './blocks/entry-hero/entry-hero.component';
import { EntryCompactComponent } from './blocks/entry-compact/entry-compact.component';
import { EntrySplitComponent } from './blocks/entry-split/entry-split.component';
import { EntryWideComponent } from './blocks/entry-wide/entry-wide.component';
import { EntryThumbComponent } from './blocks/entry-thumb/entry-thumb.component';
import { EntryQuoteComponent } from './blocks/entry-quote/entry-quote.component';
import { EntryKickerComponent } from './blocks/entry-kicker/entry-kicker.component';
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
    SourceGroupComponent,
  ],
  templateUrl: './magazine-block.component.html',
  styleUrl: './magazine-block.component.scss',
})
export class MagazineBlockComponent {
  readonly block = input.required<MagazineBlock>();
  /** Feed tags keyed by subscription id, used to render each entry's tag pills. */
  readonly feedTags = input<Map<number, SubscriptionTagDto[]>>(new Map());

  protected tagsFor(subscriptionId: number): SubscriptionTagDto[] {
    return this.feedTags().get(subscriptionId) ?? NO_TAGS;
  }

  /** Narrow a block to its entry-carrying form for the template. */
  protected entryOf(block: MagazineBlock): EntryDto {
    return (block as Extract<MagazineBlock, { entry: EntryDto }>).entry;
  }

  protected side(block: MagazineBlock): 'left' | 'right' {
    return block.kind === 'split' ? block.imageSide : 'right';
  }

  protected group(block: MagazineBlock): Extract<MagazineBlock, { kind: 'group' }> {
    return block as Extract<MagazineBlock, { kind: 'group' }>;
  }
}
