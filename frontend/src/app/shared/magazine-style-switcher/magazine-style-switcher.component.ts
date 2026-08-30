// src/app/shared/magazine-style-switcher/magazine-style-switcher.component.ts
import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { MAGAZINE_STYLES } from '../../core/magazine-style';
import { MagazineStyleService } from '../../core/magazine-style.service';

/** A small segmented Boxed | Airy control that switches the magazine reading style. */
@Component({
  selector: 'app-magazine-style-switcher',
  imports: [TranslocoPipe],
  templateUrl: './magazine-style-switcher.component.html',
  styleUrl: './magazine-style-switcher.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class MagazineStyleSwitcherComponent {
  protected readonly magazineStyle = inject(MagazineStyleService);
  protected readonly styles = MAGAZINE_STYLES;
}
