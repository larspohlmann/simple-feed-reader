import { Component, inject } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../../shared/icon/icon.component';
import { TextSizeService } from '../../../theme/text-size.service';

/** Smaller A, a bar labelled with the percentage, larger A: the text-size stepper (#1382). */
@Component({
  selector: 'app-text-size-control',
  imports: [IconComponent, TranslocoPipe],
  templateUrl: './text-size-control.component.html',
  styleUrl: './text-size-control.component.scss',
})
export class TextSizeControlComponent {
  readonly textSize = inject(TextSizeService);
}
