import { Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { LayoutService } from '../../layout.service';
import { ManageActions } from '../../feeds/manage/manage-actions.service';
import { SidebarRowMenuComponent } from './sidebar-row-menu.component';
import { SidebarRowActions } from './sidebar-row-actions.service';

/** The fine-pointer ⋯ menu of the Tags head. */
@Component({
  selector: 'app-sidebar-tags-head-menu',
  imports: [SidebarRowMenuComponent, TranslocoPipe],
  templateUrl: './sidebar-tags-head-menu.component.html',
  styleUrl: './sidebar-tags-head-menu.component.scss',
})
export class SidebarTagsHeadMenuComponent {
  protected readonly screen = inject(LayoutService);
  protected readonly manage = inject(ManageActions);
  protected readonly rows = inject(SidebarRowActions);
}
