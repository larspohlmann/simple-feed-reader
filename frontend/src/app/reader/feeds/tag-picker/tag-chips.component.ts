import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { TagOption } from './tag-search';

@Component({
  selector: 'app-tag-chips',
  imports: [IconComponent, TagGlyphComponent, TranslocoPipe],
  templateUrl: './tag-chips.component.html',
  styleUrl: './tag-chips.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TagChipsComponent {
  readonly chips = input.required<TagOption[]>();
  readonly removed = output<number>();
}
