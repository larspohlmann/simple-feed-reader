import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { EntryAudio } from '../../audio/entry-audio';

@Component({
  selector: 'app-listen-row',
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './listen-row.component.html',
  styleUrl: './listen-row.component.scss',
})
export class ListenRowComponent {
  readonly audio = input.required<EntryAudio>();
}
