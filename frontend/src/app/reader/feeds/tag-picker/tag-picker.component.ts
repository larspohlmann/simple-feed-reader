import {
  ChangeDetectionStrategy,
  Component,
  computed,
  inject,
  input,
  output,
  signal,
} from '@angular/core';
import { Dialog } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { DismissOnOutsideDirective } from '../../../shared/dismiss-on-outside.directive';
import { FormErrorComponent } from '../../../shared/form-error/form-error.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TagGlyphComponent } from '../../../shared/tag-glyph/tag-glyph.component';
import { LayoutService } from '../../layout.service';
import { SubscriptionRows } from '../../state/subscription-rows';
import { suggestedTags } from '../../state/subscription-derivations';
import { TagDto } from '../../models';
import { TagChipsComponent } from './tag-chips.component';
import { TagCreator } from './tag-creator.service';
import { TagOptionListComponent } from './tag-option-list.component';
import { TagSearch, TagSearchSource } from './tag-search';
import { TagSheetComponent } from './tag-sheet.component';

let nextId = 0;

/**
 * The tag picker shared by the add-feed and edit-feed dialogs: the selected
 * tags as chips, suggestions one tap away, and a filterable list with inline
 * creation — a combobox under a fine pointer, a full-screen sheet on phones and
 * touch. It owns no selection state: the caller passes the selected ids in and
 * applies each `toggled` id, so both dialogs share one picker (#1034, #1771).
 */
@Component({
  selector: 'app-tag-picker',
  imports: [
    DismissOnOutsideDirective,
    FormErrorComponent,
    IconComponent,
    TagChipsComponent,
    TagGlyphComponent,
    TagOptionListComponent,
    TranslocoPipe,
  ],
  templateUrl: './tag-picker.component.html',
  styleUrl: './tag-picker.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TagPickerComponent {
  readonly tags = input.required<TagDto[]>();
  readonly selected = input.required<ReadonlySet<number>>();
  /** The subscription being edited, whose own tags are never suggested back to it. */
  readonly excludeSubscriptionId = input<number | null>(null);
  readonly toggled = output<number>();

  private readonly layout = inject(LayoutService);
  private readonly dialog = inject(Dialog);
  private readonly subscriptions = inject(SubscriptionRows);
  private readonly source: TagSearchSource = {
    tags: this.tags,
    selected: this.selected,
    suggested: computed(() =>
      suggestedTags(this.subscriptions.stored(), this.selected(), this.excludeSubscriptionId()),
    ),
    toggle: (id) => this.toggled.emit(id),
  };
  protected readonly search = new TagSearch(
    this.source,
    inject(TagCreator),
    `tag-picker-list-${nextId++}`,
  );
  protected readonly sheetMode = computed(() => this.layout.isPhone() || this.layout.isCoarse());
  protected readonly open = signal(false);

  protected onKeydown(event: KeyboardEvent): void {
    if (!this.open()) {
      if (event.key === 'ArrowDown') this.open.set(true);
      return;
    }
    if (event.key === 'Escape') {
      event.stopPropagation();
      this.close();
      return;
    }
    this.search.handleKey(event);
  }

  protected onInput(text: string): void {
    this.search.setQuery(text);
    this.open.set(true);
  }

  protected close(): void {
    this.open.set(false);
    this.search.setQuery('');
  }

  protected openSheet(): void {
    this.dialog.open(TagSheetComponent, { data: this.source, panelClass: 'app-dialog' });
  }
}
