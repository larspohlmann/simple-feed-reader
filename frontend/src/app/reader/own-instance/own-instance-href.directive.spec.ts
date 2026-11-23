import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../../core/api';
import { SocialNetworkName } from '../../core/social-network';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { SubscriptionsStore } from '../state/subscriptions.store';
import { OwnInstanceHrefDirective } from './own-instance-href.directive';

@Component({
  imports: [OwnInstanceHrefDirective],
  template: `<a
    appOwnInstanceHref
    [href]="address()"
    [linkNetwork]="network()"
    [linkHomeUrl]="homeUrl()"
    target="_blank"
    >link</a
  >`,
})
class HostComponent {
  readonly address = signal<string | null>('https://lemmy.world/c/rust');
  readonly network = signal<SocialNetworkName | null>(null);
  readonly homeUrl = signal<string | null>(null);
}

describe('OwnInstanceHrefDirective', () => {
  let store: SubscriptionsStore;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    store = TestBed.inject(SubscriptionsStore);
    store.lemmyHosts.set(['lemmy.world']);
  });

  function render() {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    const href = () => fixture.nativeElement.querySelector('a').getAttribute('href');
    return { fixture, href };
  }

  it('leaves the address alone until a Lemmy account arrives, then moves it', () => {
    const { fixture, href } = render();
    expect(href()).toBe('https://lemmy.world/c/rust');

    store.socialAccounts.set([
      { id: 1, network: 'lemmy', handle: 'me', status: 'connected', serviceUrl: 'https://l.home' },
    ]);
    fixture.detectChanges();

    expect(href()).toBe('https://l.home/c/rust@lemmy.world');
  });

  it('forwards the network hint and the home address', () => {
    store.socialAccounts.set([
      { id: 1, network: 'lemmy', handle: 'me', status: 'connected', serviceUrl: 'https://l.home' },
    ]);
    const { fixture, href } = render();
    fixture.componentInstance.address.set('https://lemmy.world/post/9');
    fixture.componentInstance.homeUrl.set('https://l.home/post/42');
    fixture.detectChanges();

    expect(href()).toBe('https://l.home/post/42');
  });

  it('drops the href without an address', () => {
    const { fixture, href } = render();
    fixture.componentInstance.address.set(null);
    fixture.detectChanges();

    expect(href()).toBeNull();
  });
});
