import { TestBed } from '@angular/core/testing';
import { AvatarComponent, AvatarSize } from './avatar.component';
import { ImageProxyService } from '../proxied-image/image-proxy.service';

const failingProxy = { recover: () => Promise.resolve('failed' as const) };

interface AvatarInputs {
  url?: string | null;
  name?: string;
  toneSeed?: string | null;
  size?: AvatarSize;
}

function mount(inputs: AvatarInputs = {}) {
  TestBed.resetTestingModule();
  TestBed.configureTestingModule({
    imports: [AvatarComponent],
    providers: [{ provide: ImageProxyService, useValue: failingProxy }],
  });
  const fixture = TestBed.createComponent(AvatarComponent);
  fixture.componentRef.setInput('name', inputs.name ?? 'Ann Poster');
  for (const key of ['url', 'toneSeed', 'size'] as const) {
    if (key in inputs) fixture.componentRef.setInput(key, inputs[key]);
  }
  fixture.detectChanges();
  return fixture;
}

const host = (fixture: ReturnType<typeof mount>) => fixture.nativeElement as HTMLElement;

describe('AvatarComponent', () => {
  it('shows the picture without a referrer', () => {
    const image = host(mount({ url: 'https://cdn.test/ann.jpg' })).querySelector('img.avatar')!;

    expect(image.getAttribute('src')).toBe('https://cdn.test/ann.jpg');
    expect(image.getAttribute('referrerpolicy')).toBe('no-referrer');
    expect(host(mount({ url: 'https://cdn.test/ann.jpg' })).querySelector('.initials')).toBeNull();
  });

  it("loads a Bluesky avatar as the CDN's thumbnail", () => {
    const image = host(
      mount({ url: 'https://cdn.bsky.app/img/avatar/plain/did:plc:a/b@jpeg' }),
    ).querySelector('img.avatar')!;

    expect(image.getAttribute('src')).toBe(
      'https://cdn.bsky.app/img/avatar_thumbnail/plain/did:plc:a/b@jpeg',
    );
  });

  it('falls back to the initials when the picture fails', async () => {
    const fixture = mount({ url: 'https://cdn.test/ann.jpg' });

    host(fixture).querySelector('img.avatar')!.dispatchEvent(new Event('error'));
    await Promise.resolve();
    fixture.detectChanges();

    expect(host(fixture).querySelector('img')).toBeNull();
    expect(host(fixture).querySelector('.avatar.initials')!.textContent).toBe('AP');
  });

  it('shows the initials straight away without a picture', () => {
    expect(host(mount()).querySelector('.avatar.initials')!.textContent).toBe('AP');
  });

  it('tones the initials by their seed, the same each time', () => {
    const tone = (seed: string) =>
      host(mount({ toneSeed: seed }))
        .querySelector('.initials')!
        .className.match(/tone-\d/)![0];

    expect(tone('a')).toBe(tone('a'));
    expect(tone('b')).not.toBe(tone('a'));
  });

  it('keeps the initials neutral without a seed', () => {
    const fixture = mount();

    expect(host(fixture).classList).toContain('neutral');
    expect(host(fixture).querySelector('.initials')!.className).not.toMatch(/tone-/);
    expect(host(mount({ toneSeed: 'a' })).classList).not.toContain('neutral');
  });

  it('takes the larger size on request', () => {
    expect(host(mount({ size: 'md' })).classList).toContain('md');
    expect(host(mount()).classList).not.toContain('md');
  });
});
