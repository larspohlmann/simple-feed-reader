import { Directive, TemplateRef, inject, input } from '@angular/core';

export type ArticlePageSlotName = 'notices' | 'afterBody' | 'footer';

/** A part of the page a view fills, rendered by the page where that part belongs. */
// eslint-disable-next-line @angular-eslint/directive-selector -- a structural slot marker, named for the page it fills
@Directive({ selector: 'ng-template[articlePageSlot]' })
export class ArticlePageSlotDirective {
  readonly name = input.required<ArticlePageSlotName>({ alias: 'articlePageSlot' });
  readonly template = inject<TemplateRef<unknown>>(TemplateRef);
}
