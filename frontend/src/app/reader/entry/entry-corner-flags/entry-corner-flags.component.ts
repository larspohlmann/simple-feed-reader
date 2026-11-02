import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { FlagToggleDirective } from '../../../shared/flag-toggle/flag-toggle.directive';
import { IconComponent } from '../../../shared/icon/icon.component';
import { EntryActionHandler } from '../entry-actions/entry-action-handler';
import { EntryDto } from '../../models';

/** The keep and favourite discs in an article's bottom-left corner. */
@Component({
  selector: 'app-entry-corner-flags',
  imports: [TranslocoPipe, FlagToggleDirective, IconComponent],
  templateUrl: './entry-corner-flags.component.html',
  styleUrl: './entry-corner-flags.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class EntryCornerFlagsComponent {
  readonly entry = input.required<EntryDto>();

  protected readonly actions = inject(EntryActionHandler);
}
