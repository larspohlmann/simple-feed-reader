import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';

@Component({
  selector: 'app-short-badge',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe, IconComponent],
  templateUrl: './short-badge.component.html',
  styleUrl: './short-badge.component.scss',
})
export class ShortBadgeComponent {}
