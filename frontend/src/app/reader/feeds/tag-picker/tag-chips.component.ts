import { ChangeDetectionStrategy, Component, computed, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { TagOption } from './tag-search';

export interface LeadChip {
  readonly label: string;
  readonly icon: string;
}

interface ChipView {
  readonly key: string;
  readonly label: string;
  readonly icon: string | null;
  readonly color: string | null;
  readonly remove: () => void;
}

@Component({
  selector: 'app-tag-chips',
  imports: [IconComponent, TagGlyphComponent, TranslocoPipe],
  templateUrl: './tag-chips.component.html',
  styleUrl: './tag-chips.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TagChipsComponent {
  readonly chips = input.required<TagOption[]>();
  readonly lead = input<LeadChip | null>(null);
  readonly removed = output<number>();
  readonly leadRemoved = output<void>();

  protected readonly views = computed<ChipView[]>(() => {
    const tagViews = this.chips().map((chip): ChipView => ({
      key: `tag-${chip.id}`,
      label: chip.label,
      icon: chip.icon,
      color: chip.color,
      remove: () => this.removed.emit(chip.id),
    }));
    const lead = this.lead();
    if (lead === null) return tagViews;
    const leadView: ChipView = {
      key: 'lead',
      label: lead.label,
      icon: lead.icon,
      color: null,
      remove: () => this.leadRemoved.emit(),
    };
    return [leadView, ...tagViews];
  });
}
