import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { FaviconComponent } from './favicon.component';

@Component({
  imports: [FaviconComponent],
  template: `<app-favicon [url]="url()" />`,
})
class Host {
  readonly url = signal<string | null>(null);
}

function render() {
  const fixture = TestBed.createComponent(Host);
  fixture.detectChanges();
  return fixture;
}

describe('FaviconComponent', () => {
  it('renders the favicon image when a url is given', () => {
    const fixture = render();
    fixture.componentInstance.url.set('https://example.com/favicon.ico');
    fixture.detectChanges();

    const img: HTMLImageElement | null = fixture.nativeElement.querySelector('img');
    expect(img).not.toBeNull();
    expect(img!.getAttribute('src')).toBe('https://example.com/favicon.ico');
    expect(fixture.nativeElement.querySelector('app-icon')).toBeNull();
  });

  it('falls back to the rss icon when there is no url', () => {
    const fixture = render();
    expect(fixture.nativeElement.querySelector('img')).toBeNull();
    expect(fixture.nativeElement.querySelector('app-icon')).not.toBeNull();
  });

  it('falls back to the rss icon when the image fails to load', () => {
    const fixture = render();
    fixture.componentInstance.url.set('https://example.com/broken.png');
    fixture.detectChanges();

    const img: HTMLImageElement = fixture.nativeElement.querySelector('img');
    img.dispatchEvent(new Event('error'));
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('img')).toBeNull();
    expect(fixture.nativeElement.querySelector('app-icon')).not.toBeNull();
  });
});
