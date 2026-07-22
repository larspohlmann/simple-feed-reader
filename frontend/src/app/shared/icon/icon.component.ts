import { Component, Input } from '@angular/core';

@Component({
  selector: 'app-icon',
  template: `<span
    class="material-symbols-outlined"
    [style.font-size.px]="size"
    aria-hidden="true"
    >{{ name }}</span
  >`,
  styles: [
    `
      span {
        line-height: 1;
        user-select: none;
        font-variation-settings: 'opsz' 20;
      }
    `,
  ],
})
export class IconComponent {
  @Input({ required: true }) name!: string;
  @Input() size = 20;
}
