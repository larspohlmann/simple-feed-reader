import { ChangeDetectionStrategy, Component, computed, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { TagEntry, TagSearch } from './tag-search';

const GROUP_HEADINGS: Record<string, string> = {
  suggested: 'tagPicker.suggested',
  all: 'tagPicker.allTags',
};

/** The listbox both tag picker surfaces render; keyboard focus stays on their search input. */
@Component({
  selector: 'app-tag-option-list',
  imports: [IconComponent, TagGlyphComponent, TranslocoPipe],
  templateUrl: './tag-option-list.component.html',
  styleUrl: './tag-option-list.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TagOptionListComponent {
  readonly search = input.required<TagSearch>();

  protected readonly headings = computed(() => {
    const entries = this.search().entries();
    return entries.map((entry, index) => {
      if (entry.kind !== 'option') return null;
      const previous = entries[index - 1];
      const startsGroup = previous?.kind !== 'option' || previous.group !== entry.group;
      return startsGroup ? (GROUP_HEADINGS[entry.group] ?? null) : null;
    });
  });

  protected trackKey(entry: TagEntry): string {
    return entry.kind === 'create' ? 'create' : `${entry.group}-${entry.option.id}`;
  }
}
