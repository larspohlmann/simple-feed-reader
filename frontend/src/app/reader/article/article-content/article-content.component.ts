import { Component, ElementRef, inject, input, output, signal } from '@angular/core';
import { ArticleBody } from '../reader-view/article-body.service';

/** The article body: its typography and every decoration `ArticleBody` gives it. */
@Component({
  selector: 'app-article-content',
  template: '',
  styleUrl: './article-content.component.scss',
  providers: [ArticleBody],
  host: { class: 'content', '[innerHTML]': 'html()' },
})
export class ArticleContentComponent {
  readonly html = input.required<string>();
  readonly pageUrl = input.required<string | null>();
  readonly enclosureUrl = input<string | null>(null);
  readonly decorated = output<HTMLElement>();

  readonly element = inject<ElementRef<HTMLElement>>(ElementRef);

  constructor() {
    inject(ArticleBody).connect({
      content: signal(this.element),
      html: this.html,
      pageUrl: this.pageUrl,
      enclosureUrl: this.enclosureUrl,
      decorated: (host) => this.decorated.emit(host),
    });
  }
}
