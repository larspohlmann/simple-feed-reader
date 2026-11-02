import { Directive, TemplateRef, inject, input } from '@angular/core';

export type ArticlePageSlotName = 'notices' | 'afterBody' | 'footer';

/** A part of the page a view fills, rendered by the page where that part belongs. */
@Directive({ selector: 'ng-template[appArticlePageSlot]' })
export class ArticlePageSlotDirective {
  readonly name = input.required<ArticlePageSlotName>({ alias: 'appArticlePageSlot' });
  readonly template = inject<TemplateRef<unknown>>(TemplateRef);
}
