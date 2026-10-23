import { ChangeDetectionStrategy, Component, input, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent, IconSize } from '../../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { SelectionParams } from '../../query/query';

/** A tag-style group header: a link to the group's combined list, an optional projected menu, and the expand chevron. */
@Component({
  selector: 'app-sidebar-group-row',
  imports: [RouterLink, IconComponent, TagGlyphComponent],
  templateUrl: './sidebar-group-row.component.html',
  styleUrl: './sidebar-group-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: { '[class.current]': 'active()', '[class.open]': 'expanded()' },
})
export class SidebarGroupRowComponent {
  readonly link = input.required<SelectionParams>();
  readonly label = input.required<string>();
  readonly toggleLabel = input.required<string>();
  readonly glyph = input<string | null>(null);
  readonly glyphColor = input<string | null>(null);
  readonly glyphSize = input<IconSize>('md');
  readonly count = input(0);
  readonly active = input(false);
  readonly expanded = input(false);
  readonly toggled = output();
}
