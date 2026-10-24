import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { TagEntry } from './tag-search';

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
  readonly entries = input.required<TagEntry[]>();
  readonly activeIndex = input(0);
  readonly listId = input.required<string>();
  readonly creating = input(false);
  readonly picked = output<number>();
  readonly createRequested = output<void>();

  protected readonly headings = computed(() =>
    this.entries().map((entry, index) => {
      if (entry.kind !== 'option') return null;
      const previous = this.entries()[index - 1];
      const startsGroup = previous?.kind !== 'option' || previous.group !== entry.group;
      return startsGroup ? (GROUP_HEADINGS[entry.group] ?? null) : null;
    }),
  );

  optionId(index: number): string {
    return `${this.listId()}-option-${index}`;
  }

  protected trackKey(entry: TagEntry): string {
    return entry.kind === 'create' ? 'create' : `${entry.group}-${entry.option.id}`;
  }
}
