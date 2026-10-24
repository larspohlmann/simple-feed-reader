import { ChangeDetectionStrategy, Component, inject, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { LayoutService } from '../../layout.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { SidebarRowMenuComponent } from './sidebar-row-menu.component';
import { SidebarRowActions } from './sidebar-row-actions.service';

@Component({
  selector: 'app-sidebar-tags-head-menu',
  imports: [SidebarRowMenuComponent, IconComponent, TranslocoPipe],
  templateUrl: './sidebar-tags-head-menu.component.html',
  styleUrl: './sidebar-tags-head-menu.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class SidebarTagsHeadMenuComponent {
  readonly organising = input.required<boolean>();

  protected readonly screen = inject(LayoutService);
  protected readonly manage = inject(ManageActions);
  protected readonly rows = inject(SidebarRowActions);
}
