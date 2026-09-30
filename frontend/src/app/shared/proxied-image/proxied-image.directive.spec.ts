import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ImageProxyService } from './image-proxy.service';
import { ProxiedImageDirective } from './proxied-image.directive';

@Component({
  imports: [ProxiedImageDirective],
  template: `<img appProxiedImage [src]="src" (imageFailed)="failed.set(true)" alt="" />`,
})
class HostComponent {
  src = 'https://www.oxmoxhh.de/cover.png';
  readonly failed = signal(false);
}

describe('ProxiedImageDirective', () => {
  let recover: jest.Mock<Promise<boolean>, [HTMLImageElement]>;

  function mount() {
    const fixture = TestBed.createComponent(HostComponent);
    fixture.detectChanges();
    return fixture;
  }

  beforeEach(() => {
    recover = jest.fn();
    TestBed.configureTestingModule({
      imports: [HostComponent],
      providers: [{ provide: ImageProxyService, useValue: { recover } }],
    });
  });

  it('hands a failed image to the proxy and stays quiet when the proxy recovers it', async () => {
    recover.mockResolvedValue(true);
    const fixture = mount();
    const img = fixture.nativeElement.querySelector('img') as HTMLImageElement;

    img.dispatchEvent(new Event('error'));
    await fixture.whenStable();

    expect(recover).toHaveBeenCalledWith(img);
    expect(fixture.componentInstance.failed()).toBe(false);
  });

  it('reports the failure once the proxy cannot help', async () => {
    recover.mockResolvedValue(false);
    const fixture = mount();

    fixture.nativeElement.querySelector('img').dispatchEvent(new Event('error'));
    await fixture.whenStable();

    expect(fixture.componentInstance.failed()).toBe(true);
  });
});
