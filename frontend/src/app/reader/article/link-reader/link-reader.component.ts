import { Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { ArticlePageComponent } from '../article-page/article-page.component';
import { noAutoplayRegion, provideArticlePage } from '../article-page/provide-article-page';
import { LinkArticleSource } from './link-article-source.service';

/** Where the back button returns: the article the card sits in, or the list. */
export type LinkReaderReturn = 'article' | 'list';

/** A link card's page in the reader, with only the chrome a page that is no entry has. */
@Component({
  selector: 'app-link-reader',
  imports: [TranslocoPipe, ArticlePageComponent],
  providers: provideArticlePage({ source: LinkArticleSource, autoplayRegion: noAutoplayRegion }),
  templateUrl: './link-reader.component.html',
  styleUrl: './link-reader.component.scss',
})
export class LinkReaderComponent {
  readonly fullscreen = input(false);
  readonly returnsTo = input.required<LinkReaderReturn>();

  readonly back = output<void>();

  protected readonly source = inject(LinkArticleSource);
  protected readonly backLabelKey = computed(() =>
    this.returnsTo() === 'article' ? 'reader.backToArticle' : 'reader.backToList',
  );
}
