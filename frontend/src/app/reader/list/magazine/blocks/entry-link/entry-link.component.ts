import { ChangeDetectionStrategy, Component, computed } from '@angular/core';
import { EntryKickerLineComponent } from '../../entry-kicker-line.component';
import { EntryMetaComponent } from '../../../entry-meta/entry-meta.component';
import { MoreSourcesComponent } from '../../more-sources.component';
import { EntryBlockBase } from '../../entry-block-base';
import { LinkCardComponent } from '../../../../link-card/link-card.component';
import { LinkCardPlaceholderComponent } from '../../../../link-card/link-card-placeholder.component';
import { OpensEntryDirective } from '../../../opens-entry.directive';
import { sharedArticleCard } from '../../../../trending/shared-article-card';
import { withoutTrailingLinks } from '../../../../entry/entry-text';

@Component({
  selector: 'app-entry-link',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    OpensEntryDirective,
    EntryKickerLineComponent,
    EntryMetaComponent,
    MoreSourcesComponent,
    LinkCardComponent,
    LinkCardPlaceholderComponent,
  ],
  templateUrl: './entry-link.component.html',
  styleUrl: './entry-link.component.scss',
})
export class EntryLinkComponent extends EntryBlockBase {
  /** A Trending row no followed outlet carries is the article its posts share: the card heads it (#2229). */
  protected readonly ledByItsCard = computed(() => {
    const story = this.story();
    return story ? sharedArticleCard(story.articles[0]) !== null : false;
  });
  protected readonly title = computed(() => this.withoutCardedLink(this.headline()));
  protected readonly dek = computed(() => this.withoutCardedLink(this.snippet()));

  private withoutCardedLink(text: string): string {
    return this.entry().linkCards.length > 0 ? withoutTrailingLinks(text) : text;
  }
}
