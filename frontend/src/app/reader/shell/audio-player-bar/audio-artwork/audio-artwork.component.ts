import { ChangeDetectionStrategy, Component, input, linkedSignal } from '@angular/core';
import { FaviconComponent } from '../../../../shared/favicon/favicon.component';
import { ProxiedImageDirective } from '../../../../shared/proxied-image/proxied-image.directive';
import { AudioTrack } from '../../../audio-player.service';

/** A track's artwork square; the feed's favicon stands in when it has none or it fails to load. */
@Component({
  selector: 'app-audio-artwork',
  imports: [FaviconComponent, ProxiedImageDirective],
  templateUrl: './audio-artwork.component.html',
  styleUrl: './audio-artwork.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AudioArtworkComponent {
  readonly track = input.required<AudioTrack>();

  protected readonly imageUrl = linkedSignal(() => this.track().imageUrl);
}
