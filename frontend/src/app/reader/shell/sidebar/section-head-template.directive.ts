import { Directive, TemplateRef } from '@angular/core';
import { PersistedToggle } from '../../../core/preferences/persisted-toggle';
import { FeedGroupName } from '../../../core/feed-group';

export interface SectionHeadView {
  readonly group: FeedGroupName;
  readonly section: PersistedToggle;
  readonly label: string;
  readonly toggleLabel: string;
}

interface SectionHeadContext {
  readonly $implicit: SectionHeadView;
  readonly menu: TemplateRef<unknown>;
  readonly sheet: TemplateRef<unknown> | null;
}

/** Types the sidebar's `#sectionHead` template, so the Tags and Feeds heads share one strictly checked body. */
@Directive({ selector: 'ng-template[appSectionHead]' })
export class SectionHeadTemplateDirective {
  static ngTemplateContextGuard(
    _directive: SectionHeadTemplateDirective,
    context: unknown,
  ): context is SectionHeadContext {
    return true;
  }
}
