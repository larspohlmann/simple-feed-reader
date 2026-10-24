import { ChangeDetectionStrategy, Component, computed, inject } from '@angular/core';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { TranslocoPipe } from '@jsverse/transloco';
import { ButtonComponent } from '../../../shared/button/button.component';
import { FormErrorComponent } from '../../../shared/form-error/form-error.component';
import { IconComponent } from '../../../shared/icon/icon.component';
import { OverlayPanelComponent } from '../../../shared/overlay-panel/overlay-panel.component';
import { TagChipsComponent } from './tag-chips.component';
import { TagCreator } from './tag-creator.service';
import { TagOptionListComponent, tagOptionId } from './tag-option-list.component';
import { TagSearch, TagSearchSource } from './tag-search';

/** The tag picker on phones and touch screens: the whole list on a full-screen sheet over the dialog. */
@Component({
  selector: 'app-tag-sheet',
  imports: [
    ButtonComponent,
    FormErrorComponent,
    IconComponent,
    OverlayPanelComponent,
    TagChipsComponent,
    TagOptionListComponent,
    TranslocoPipe,
  ],
  templateUrl: './tag-sheet.component.html',
  styleUrl: './tag-sheet.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class TagSheetComponent {
  readonly ref = inject(DialogRef);
  protected readonly source = inject<TagSearchSource>(DIALOG_DATA);
  protected readonly search = new TagSearch(this.source, inject(TagCreator));
  protected readonly listId = 'tag-sheet-list';
  protected readonly activeId = computed(() =>
    this.search.entries().length ? tagOptionId(this.listId, this.search.activeIndex()) : null,
  );
}
