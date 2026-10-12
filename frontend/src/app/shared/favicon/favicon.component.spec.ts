import { TestBed } from '@angular/core/testing';
import { Component, signal } from '@angular/core';
import { FaviconComponent } from './favicon.component';

@Component({
  imports: [FaviconComponent],
  template: `<app-favicon [url]="url()" [avatarUrl]="avatarUrl()" [size]="14" />`,
})
class Host {
  readonly url = signal<string | null>(null);
  readonly avatarUrl = signal<string | null>(null);
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

  it('tries the next favicon after one failed to load', () => {
    const fixture = render();
    fixture.componentInstance.url.set('https://example.com/broken.png');
    fixture.detectChanges();
    fixture.nativeElement.querySelector('img').dispatchEvent(new Event('error'));
    fixture.detectChanges();

    fixture.componentInstance.url.set('https://other.example/favicon.ico');
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('img')?.getAttribute('src')).toBe(
      'https://other.example/favicon.ico',
    );
  });

  describe('with an avatar', () => {
    const renderWithAvatar = () => {
      const fixture = render();
      fixture.componentInstance.url.set('https://example.com/favicon.ico');
      fixture.componentInstance.avatarUrl.set('https://cdn.example/bob.png');
      fixture.detectChanges();
      return fixture;
    };

    it('shows the avatar round, decorative and lazy at the favicon size, in place of the favicon', () => {
      const images = renderWithAvatar().nativeElement.querySelectorAll('img');

      expect(images).toHaveLength(1);
      const avatar: HTMLImageElement = images[0];
      expect(avatar.getAttribute('src')).toBe('https://cdn.example/bob.png');
      expect(avatar.classList).toContain('avatar');
      expect(avatar.getAttribute('alt')).toBe('');
      expect(avatar.getAttribute('loading')).toBe('lazy');
      expect(avatar.getAttribute('referrerpolicy')).toBe('no-referrer');
      expect(avatar.getAttribute('width')).toBe('14');
      expect(avatar.getAttribute('height')).toBe('14');
    });

    it('falls back to the favicon when the avatar fails to load', () => {
      const fixture = renderWithAvatar();
      fixture.nativeElement.querySelector('img').dispatchEvent(new Event('error'));
      fixture.detectChanges();

      const img: HTMLImageElement = fixture.nativeElement.querySelector('img');
      expect(img.getAttribute('src')).toBe('https://example.com/favicon.ico');
      expect(img.classList).not.toContain('avatar');
    });

    it('tries the next avatar after one failed to load', () => {
      const fixture = renderWithAvatar();
      fixture.nativeElement.querySelector('img').dispatchEvent(new Event('error'));
      fixture.detectChanges();

      fixture.componentInstance.avatarUrl.set('https://cdn.example/amy.png');
      fixture.detectChanges();

      expect(fixture.nativeElement.querySelector('img.avatar')?.getAttribute('src')).toBe(
        'https://cdn.example/amy.png',
      );
    });

    it('shows the favicon when there is no avatar', () => {
      const fixture = renderWithAvatar();
      fixture.componentInstance.avatarUrl.set(null);
      fixture.detectChanges();

      const img: HTMLImageElement = fixture.nativeElement.querySelector('img');
      expect(img.getAttribute('src')).toBe('https://example.com/favicon.ico');
    });
  });
});
