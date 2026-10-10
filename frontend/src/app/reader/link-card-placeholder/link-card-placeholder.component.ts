import { ChangeDetectionStrategy, Component } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';

/** Holds a link card's place while its post is still being completed (#1502). */
@Component({
  selector: 'app-link-card-placeholder',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslocoPipe],
  templateUrl: './link-card-placeholder.component.html',
  styleUrl: './link-card-placeholder.component.scss',
})
export class LinkCardPlaceholderComponent {}
