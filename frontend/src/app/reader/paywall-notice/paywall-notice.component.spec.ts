import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { PaywallNoticeComponent } from './paywall-notice.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';

@Component({
  imports: [PaywallNoticeComponent],
  template: `<app-paywall-notice [url]="url()" />`,
})
class Host {
  readonly url = signal<string | null>(null);
}

describe('PaywallNoticeComponent', () => {
  const mount = async () => {
    await TestBed.configureTestingModule({
      imports: [Host, provideTranslocoTesting()],
    }).compileComponents();
    const fixture = TestBed.createComponent(Host);
    fixture.detectChanges();
    return fixture;
  };

  it('states the body is the free preview of a paywalled article', async () => {
    const el: HTMLElement = (await mount()).nativeElement;
    expect(el.textContent).toContain('free preview of a paywalled article');
    expect(el.querySelector('.icon')?.getAttribute('aria-hidden')).toBe('true');
  });

  it('renders the publisher link when a url is given', async () => {
    const fixture = await mount();
    fixture.componentInstance.url.set('https://pub.test/a');
    fixture.detectChanges();

    const link = fixture.nativeElement.querySelector('a') as HTMLAnchorElement;
    expect(link.getAttribute('href')).toBe('https://pub.test/a');
    expect(link.getAttribute('rel')).toBe('noopener noreferrer');
  });

  it('renders no link when the url is null', async () => {
    expect((await mount()).nativeElement.querySelector('a')).toBeNull();
  });
});
