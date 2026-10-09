import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';

/** `glyph` fits a portrait thumbnail too narrow for the word; it keeps the word for screen readers. */
export type ShortBadgeForm = 'label' | 'glyph';

/** Sits in the top-right corner of its nearest positioned ancestor: the frame around an entry's image. */
@Component({
  selector: 'app-short-badge',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, IconComponent],
  host: { '[class.glyph]': "form() === 'glyph'" },
  templateUrl: './short-badge.component.html',
  styleUrl: './short-badge.component.scss',
})
export class ShortBadgeComponent {
  readonly form = input<ShortBadgeForm>('label');
}
