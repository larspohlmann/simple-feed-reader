import { Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe, TranslocoService } from '@jsverse/transloco';
import { LayoutService } from '../../layout.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { TagDto } from '../../models';
import { SidebarRowMenuComponent } from './sidebar-row-menu.component';
import { SidebarRowActions } from './sidebar-row-actions.service';

/** The fine-pointer ⋯ menu of a tag row, or of the Tags head when no tag is given. */
@Component({
  selector: 'app-sidebar-tag-menu',
  imports: [SidebarRowMenuComponent, TranslocoPipe],
  templateUrl: './sidebar-tag-menu.component.html',
  styleUrl: './sidebar-tag-menu.component.scss',
})
export class SidebarTagMenuComponent {
  readonly tag = input<TagDto | null>(null);

  protected readonly screen = inject(LayoutService);
  protected readonly manage = inject(ManageActions);
  protected readonly rows = inject(SidebarRowActions);
  private readonly transloco = inject(TranslocoService);

  protected readonly menuKey = computed(() => {
    const tag = this.tag();
    return tag === null ? 'tags-head' : 'tag-' + tag.id;
  });
  protected readonly name = computed(
    () => this.tag()?.name ?? this.transloco.translate('reader.tags'),
  );
}
