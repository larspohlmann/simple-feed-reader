import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { RouterLink } from '@angular/router';
import { IconComponent, IconSize } from '../../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { SelectionParams } from '../../query/query';
import { TagInclusion } from '../../models';
import { ownExcludedSurfaces } from '../../feed-exclusions';
import { FeedExclusionMarkerComponent } from './feed-exclusion-marker.component';

/** A tag-style group header: a link to the group's combined list, an optional projected subtag pill and menu, and the expand chevron. */
@Component({
  selector: 'app-sidebar-group-row',
  imports: [RouterLink, IconComponent, TagGlyphComponent, FeedExclusionMarkerComponent],
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
  /** A tag's own switches; a row without them carries no exclusion marker. */
  readonly inclusion = input<TagInclusion | null>(null);
  readonly toggled = output();

  protected readonly excludedSurfaces = computed(() => {
    const inclusion = this.inclusion();
    return inclusion === null ? [] : ownExcludedSurfaces(inclusion);
  });
  protected readonly excluded = computed(() => this.excludedSurfaces().length > 0);
}
