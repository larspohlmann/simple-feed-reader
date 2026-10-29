import { ChangeDetectionStrategy, Component, input, model } from '@angular/core';
import { IconComponent } from '../../../shared/icon/icon.component';

/** A collapsible panel of the article header: Contents, Chapters, Transcript (#1880). */
@Component({
  selector: 'app-reader-panel',
  imports: [IconComponent],
  templateUrl: './reader-panel.component.html',
  styleUrl: './reader-panel.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ReaderPanelComponent {
  readonly label = input.required<string>();
  readonly open = model(false);
}
