import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { IconComponent } from '../../../shared/icon/icon.component';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { EntryDto } from '../../models';
import { ShareButtonComponent } from '../share/share-button.component';

/** The discs in the article's bottom-left corner: share, keep and favourite. */
@Component({
  selector: 'app-article-corner-actions',
  imports: [TranslocoPipe, FlagToggleDirective, IconComponent, ShareButtonComponent],
  templateUrl: './article-corner-actions.component.html',
  styleUrl: './article-corner-actions.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ArticleCornerActionsComponent {
  readonly entry = input.required<EntryDto>();

  protected readonly actions = inject(EntryActionHandler);
}
