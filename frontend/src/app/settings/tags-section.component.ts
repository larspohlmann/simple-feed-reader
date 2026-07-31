// src/app/settings/tags-section.component.ts
import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { TranslocoPipe } from '@jsverse/transloco';
import { CdkDrag, CdkDragDrop, CdkDragHandle, CdkDropList } from '@angular/cdk/drag-drop';
import { IconComponent } from '../shared/icon/icon.component';
import { TagGlyphComponent } from '../shared/tag-glyph/tag-glyph.component';
import { TagsStore } from '../reader/tags.store';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { ManageActions } from '../reader/manage/manage-actions.service';
import { ButtonComponent } from '../shared/button/button.component';
import { SettingsCardComponent } from '../shared/settings-card/settings-card.component';
import { SkeletonComponent } from '../shared/skeleton/skeleton.component';
import { ErrorBannerComponent } from '../shared/error-banner/error-banner.component';
import { FieldComponent } from '../shared/field/field.component';
import { ColorFieldComponent } from '../shared/color-field/color-field.component';
import { IconPickerComponent } from '../shared/icon-picker/icon-picker.component';
import { ReaderApi } from '../reader/reader-api';
import { parseProblem } from '../core/problem';
import { TagDto } from '../reader/models';

@Component({
  selector: 'app-tags-section',
  imports: [
    ButtonComponent,
    IconComponent,
    TagGlyphComponent,
    TranslocoPipe,
    SettingsCardComponent,
    SkeletonComponent,
    ErrorBannerComponent,
    FieldComponent,
    ColorFieldComponent,
    IconPickerComponent,
    CdkDropList,
    CdkDrag,
    CdkDragHandle,
  ],
  templateUrl: './tags-section.component.html',
  styleUrl: './tags-section.component.scss',
})
export class TagsSectionComponent implements OnInit {
  readonly tagsStore = inject(TagsStore);
  private readonly subs = inject(SubscriptionsStore);
  readonly manage = inject(ManageActions);
  private readonly api = inject(ReaderApi);

  /** The row currently in edit mode, or null. Only one row edits at a time. */
  readonly editingId = signal<number | null>(null);
  readonly draftName = signal('');
  readonly draftColor = signal<string | null>(null);
  readonly draftIcon = signal<string | null>(null);
  /** The server's own message for a failed save, so the banner never shows a
   *  fixed string instead of what actually went wrong. */
  readonly saveError = signal<string | null>(null);

  /** feed count per tag id, derived from the subscription list. */
  readonly usage = computed<Record<number, number>>(() => {
    const map: Record<number, number> = {};
    for (const s of this.subs.subscriptions()) {
      for (const t of s.tags) map[t.id] = (map[t.id] ?? 0) + 1;
    }
    return map;
  });

  ngOnInit(): void {
    // The deleted SettingsComponent used to preload these for all sections;
    // with per-route sections, the one section that needs them loads them.
    this.tagsStore.load();
    this.subs.load();
  }

  startEdit(tag: TagDto): void {
    this.editingId.set(tag.id);
    this.draftName.set(tag.name);
    this.draftColor.set(tag.color);
    this.draftIcon.set(tag.icon);
    this.saveError.set(null);
  }

  cancelEdit(): void {
    this.editingId.set(null);
  }

  saveEdit(): void {
    const id = this.editingId();
    const name = this.draftName().trim();
    // An empty name is the one client-side rule: the server rejects it too, but
    // sending a request we know will 422 just to be told so is wasteful.
    if (id === null || name === '') return;

    this.saveError.set(null);
    this.api.updateTag(id, { name, color: this.draftColor(), icon: this.draftIcon() }).subscribe({
      next: () => {
        this.editingId.set(null);
        this.tagsStore.load();
        this.subs.load(); // the embedded tag colour and name on each feed changed too
      },
      error: (e: HttpErrorResponse) => {
        const problem = parseProblem(e);
        this.saveError.set(problem.errors?.['name']?.[0] ?? problem.detail ?? problem.title);
      },
    });
  }

  /**
   * Persist a new tag order after a drop. The whole list is one flat drop list:
   * nesting cdkDropLists silently breaks cross-list drag, which is a standing
   * rule in this project (see CLAUDE.md).
   */
  onTagDrop(event: CdkDragDrop<TagDto[]>): void {
    if (event.previousIndex === event.currentIndex) return;

    const ids = this.tagsStore.tags().map((t) => t.id);
    const [moved] = ids.splice(event.previousIndex, 1);
    ids.splice(event.currentIndex, 0, moved);
    this.manage.reorderTags(ids);
  }
}
