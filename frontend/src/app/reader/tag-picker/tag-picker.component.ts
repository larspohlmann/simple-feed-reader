import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TagGlyphComponent } from '../../shared/tag-glyph/tag-glyph.component';
import { TagDto } from '../models';

/**
 * The tag picker shared by the add-feed and edit-feed dialogs: a wrapping row
 * of toggle pills over the caller's tag list. It owns no selection state — the
 * caller passes the selected ids in and applies each `toggled` id — so both
 * dialogs render the same picker and a fix reaches both at once (#1034).
 */
@Component({
  selector: 'app-tag-picker',
  imports: [TagGlyphComponent],
  templateUrl: './tag-picker.component.html',
  styleUrl: './tag-picker.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TagPickerComponent {
  readonly tags = input.required<TagDto[]>();
  readonly selected = input.required<ReadonlySet<number>>();
  readonly toggled = output<number>();
}
