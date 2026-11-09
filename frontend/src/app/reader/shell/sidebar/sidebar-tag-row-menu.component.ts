import { ChangeDetectionStrategy, Component, computed, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LayoutService } from '../../layout.service';
import { TagActions } from '../../feeds/manage/tag-actions.service';
import { TagDto } from '../../models';
import { SidebarRowMenuComponent } from './sidebar-row-menu.component';
import { SidebarRowActions } from './sidebar-row-actions.service';

@Component({
  selector: 'app-sidebar-tag-row-menu',
  imports: [SidebarRowMenuComponent, TranslocoPipe],
  templateUrl: './sidebar-tag-row-menu.component.html',
  styleUrl: './sidebar-tag-row-menu.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SidebarTagRowMenuComponent {
  readonly tag = input.required<TagDto>();

  protected readonly screen = inject(LayoutService);
  protected readonly tagActions = inject(TagActions);
  protected readonly rows = inject(SidebarRowActions);
  protected readonly roomBelow = computed(() => this.rows.canCreateTagUnder(this.tag()));
}
