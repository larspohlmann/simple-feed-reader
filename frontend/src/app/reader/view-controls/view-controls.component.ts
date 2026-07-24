import { Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { ThemeService } from '../../theme/theme.service';
import { ThemeMode } from '../../theme/themes/registry';
import { ReadingLayoutService } from '../reading-layout.service';

/**
 * The reading-layout and theme segmented controls. They live in the sidebar so
 * the top bar has room for the article's back button and reader-mode switch.
 */
@Component({
  selector: 'app-view-controls',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './view-controls.component.html',
  styleUrl: './view-controls.component.scss',
})
export class ViewControlsComponent {
  readonly theme = inject(ThemeService);
  readonly layout = inject(ReadingLayoutService);

  // The label for each mode comes from the `reader.theme.<id>` translation key.
  readonly modes: { id: ThemeMode; icon: string }[] = [
    { id: 'light', icon: 'light_mode' },
    { id: 'dark', icon: 'dark_mode' },
    { id: 'system', icon: 'contrast' },
  ];
}
