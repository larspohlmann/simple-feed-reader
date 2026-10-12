import { TestBed } from '@angular/core/testing';
import { SocialNetworkName } from '../../core/social-network';
import { NetworkLogoComponent } from './network-logo.component';

function render(network: SocialNetworkName): HTMLElement {
  TestBed.resetTestingModule();
  const fixture = TestBed.createComponent(NetworkLogoComponent);
  fixture.componentRef.setInput('network', network);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

describe('NetworkLogoComponent', () => {
  it('draws each network its own logo, hidden from assistive technology', () => {
    const bluesky = render('bluesky').querySelector('svg')!;
    const mastodon = render('mastodon').querySelector('svg')!;

    expect(bluesky.getAttribute('aria-hidden')).toBe('true');
    expect(bluesky.classList).toContain('bluesky');
    expect(mastodon.classList).toContain('mastodon');
    expect(bluesky.querySelector('path')?.getAttribute('d')).not.toBe(
      mastodon.querySelector('path')?.getAttribute('d'),
    );
  });
});
