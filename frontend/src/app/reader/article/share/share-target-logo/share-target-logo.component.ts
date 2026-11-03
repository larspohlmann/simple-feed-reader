import { ChangeDetectionStrategy, Component, input } from '@angular/core';
import { IconComponent } from '../../../../shared/icon/icon.component';
import { MaskedLogoComponent } from '../../../../shared/masked-logo/masked-logo.component';
import { ConfiguredTarget, SHARE_GLYPH, shareLogoUrl } from '../share-targets';

@Component({
  selector: 'app-share-target-logo',
  imports: [IconComponent, MaskedLogoComponent],
  templateUrl: './share-target-logo.component.html',
  styleUrl: './share-target-logo.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ShareTargetLogoComponent {
  readonly target = input.required<ConfiguredTarget>();
  protected readonly glyph = SHARE_GLYPH;
  protected readonly logoUrl = shareLogoUrl;
}
