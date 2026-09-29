import { ChangeDetectionStrategy, Component } from '@angular/core';

@Component({
  selector: 'app-caught-up-illustration',
  templateUrl: './caught-up-illustration.component.html',
  styleUrl: './caught-up-illustration.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class CaughtUpIllustrationComponent {}
