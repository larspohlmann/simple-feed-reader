import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';

/** Sits in the top-right corner of its nearest positioned ancestor: the frame around an entry's image. */
@Component({
  selector: 'app-short-badge',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe],
  templateUrl: './short-badge.component.html',
  styleUrl: './short-badge.component.scss',
})
export class ShortBadgeComponent {}
