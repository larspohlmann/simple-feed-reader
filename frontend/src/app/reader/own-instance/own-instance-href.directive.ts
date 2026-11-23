import { computed, Directive, inject, input } from '@angular/core';
import { SocialNetworkName } from '../../core/social-network';
import { OwnInstanceLinks } from './own-instance-links.service';

@Directive({
  selector: 'a[appOwnInstanceHref]',
  host: { '[attr.href]': 'resolvedHref()' },
})
export class OwnInstanceHrefDirective {
  private readonly links = inject(OwnInstanceLinks);

  readonly href = input.required<string | null>();
  readonly linkNetwork = input<SocialNetworkName | null>(null);
  readonly linkHomeUrl = input<string | null>(null);

  protected readonly resolvedHref = computed(() => {
    const address = this.href();
    if (address === null) return null;
    return this.links.open(address, { network: this.linkNetwork(), homeUrl: this.linkHomeUrl() });
  });
}
