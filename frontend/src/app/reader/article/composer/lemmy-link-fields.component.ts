import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { FieldComponent } from '../../../shared/field/field.component';
import { inputValue } from '../../../shared/input-value';
import { LemmyLinkFieldsDto } from '../../entry-social';
import { ComposerLane } from './composer-lane';
import { ComposerLanes } from './composer-lanes';

/** A Lemmy lane's own fields: the community a link post goes to and its title, with the title's counter. */
@Component({
  selector: 'app-lemmy-link-fields',
  imports: [FieldComponent, TranslocoPipe],
  templateUrl: './lemmy-link-fields.component.html',
  styleUrl: './lemmy-link-fields.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class LemmyLinkFieldsComponent {
  private readonly lanes = inject(ComposerLanes);
  readonly lane = input.required<ComposerLane>();
  readonly lemmy = input.required<LemmyLinkFieldsDto>();
  readonly disabled = input(false);

  protected controlId(control: string): string {
    return this.lanes.controlId(control, this.lane());
  }

  protected chooseCommunity(event: Event): void {
    this.lane().community.set(Number(inputValue(event)));
  }

  protected writeTitle(event: Event): void {
    this.lane().writeTitle(inputValue(event));
  }
}
