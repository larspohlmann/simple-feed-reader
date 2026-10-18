import { Component, TemplateRef, computed, contentChild, inject, input } from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import { IconComponent } from '../../../shared/icon/icon.component';
import { DismissOnOutsideDirective } from '../../../shared/dismiss-on-outside.directive';
import { SidebarRowActions } from './sidebar-row-actions.service';

/** A sidebar row's fine-pointer ⋯ menu; its items come as an `<ng-template>`, built only while it is open. */
@Component({
  selector: 'app-sidebar-row-menu',
  imports: [NgTemplateOutlet, IconComponent, DismissOnOutsideDirective],
  templateUrl: './sidebar-row-menu.component.html',
  styleUrl: './sidebar-row-menu.component.scss',
  host: { class: 'rowmenu' },
})
export class SidebarRowMenuComponent {
  readonly menuKey = input.required<string>();
  readonly label = input.required<string>();

  protected readonly rows = inject(SidebarRowActions);
  protected readonly items = contentChild.required(TemplateRef);
  protected readonly open = computed(() => this.rows.menuFor() === this.menuKey());
}
