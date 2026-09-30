import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { ImageProxyService } from './image-proxy.service';

describe('ImageProxyService', () => {
  const IMAGE = 'https://www.oxmoxhh.de/wp-content/uploads/2026/09/cover-413x450.png';
  interface ObjectUrls {
    createObjectURL: unknown;
    revokeObjectURL: unknown;
  }
  const urls = URL as unknown as ObjectUrls;
  const original = { create: urls.createObjectURL, revoke: urls.revokeObjectURL };
  let service: ImageProxyService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
      ],
    });
    service = TestBed.inject(ImageProxyService);
    http = TestBed.inject(HttpTestingController);
    urls.createObjectURL = jest.fn(() => 'blob:https://app.test/7f3');
    urls.revokeObjectURL = jest.fn();
  });

  afterEach(() => {
    http.verify();
    urls.createObjectURL = original.create;
    urls.revokeObjectURL = original.revoke;
  });

  function image(source: string): HTMLImageElement {
    const img = document.createElement('img');
    img.src = source;
    return img;
  }

  it('swaps in the proxied bytes for an image that failed direct', async () => {
    const img = image(IMAGE);
    const recovered = service.recover(img);

    const request = http.expectOne(
      (request) =>
        request.url === 'https://api.test/api/image-proxy' && request.params.get('url') === IMAGE,
    );
    expect(request.request.responseType).toBe('blob');
    request.flush(new Blob(['png']));

    expect(await recovered).toBe('recovered');
    expect(img.src).toBe('blob:https://app.test/7f3');
  });

  it('releases the object URL once the swapped image has loaded', async () => {
    const img = image(IMAGE);
    const recovered = service.recover(img);
    http.expectOne(() => true).flush(new Blob(['png']));
    await recovered;

    img.dispatchEvent(new Event('load'));

    expect(urls.revokeObjectURL).toHaveBeenCalledWith('blob:https://app.test/7f3');
  });

  it('reports failure when the proxy cannot fetch it either', async () => {
    const img = image(IMAGE);
    const recovered = service.recover(img);
    http.expectOne(() => true).flush(new Blob(['{}']), { status: 404, statusText: 'Not Found' });

    expect(await recovered).toBe('failed');
    expect(img.src).toBe(IMAGE);
  });

  it('never proxies the same source for the same element twice', async () => {
    const img = image(IMAGE);
    const first = service.recover(img);
    http.expectOne(() => true).flush(new Blob(['{}']), { status: 404, statusText: 'Not Found' });
    await first;

    expect(await service.recover(img)).toBe('failed');
    http.expectNone(() => true);
  });

  it('drops the srcset and sizes that would override the swapped src', async () => {
    const img = image(IMAGE);
    img.srcset = 'https://www.oxmoxhh.de/cover-768x836.png 768w';
    img.sizes = '100vw';
    const recovered = service.recover(img);
    http.expectOne(() => true).flush(new Blob(['png']));
    await recovered;

    expect(img.hasAttribute('srcset')).toBe(false);
    expect(img.hasAttribute('sizes')).toBe(false);
    expect(img.src).toBe('blob:https://app.test/7f3');
  });

  it('leaves blob, data and same-origin sources alone', async () => {
    expect(await service.recover(image('blob:https://app.test/1'))).toBe('failed');
    expect(await service.recover(image('data:image/png;base64,AAAA'))).toBe('failed');
    expect(await service.recover(image(`${location.origin}/assets/logo.png`))).toBe('failed');
    http.expectNone(() => true);
  });

  it('does not swap in a proxied result once the element shows another image', async () => {
    const img = image(IMAGE);
    const outcome = service.recover(img);
    img.src = 'https://www.oxmoxhh.de/other.png';
    http.expectOne(() => true).flush(new Blob(['png']));

    expect(await outcome).toBe('superseded');
    expect(img.src).toBe('https://www.oxmoxhh.de/other.png');
  });

  it('reports a failure as superseded once the element shows another image', async () => {
    const img = image(IMAGE);
    const outcome = service.recover(img);
    img.src = 'https://www.oxmoxhh.de/other.png';
    http.expectOne(() => true).flush(new Blob(['{}']), { status: 404, statusText: 'Not Found' });

    expect(await outcome).toBe('superseded');
  });

  it('resolves to failed without a request for an unparseable source', async () => {
    const img = document.createElement('img');
    img.setAttribute('src', 'https://[bad');

    expect(await service.recover(img)).toBe('failed');
    http.expectNone(() => true);
  });

  it('keeps at most four proxy requests in flight and starts the next as one ends', async () => {
    const outcomes = Array.from({ length: 6 }, (_, index) =>
      service.recover(image(`https://www.oxmoxhh.de/${index}.png`)),
    );
    const open = () => http.match(() => true);

    const first = open();
    expect(first).toHaveLength(4);
    first[0].flush(new Blob(['png']));
    await outcomes[0];

    const next = open();
    expect(next).toHaveLength(1);
    [...first.slice(1), ...next].forEach((request) => request.flush(new Blob(['png'])));
    await new Promise((resolve) => setTimeout(resolve));
    const last = open();
    expect(last).toHaveLength(1);
    last[0].flush(new Blob(['png']));
    await Promise.all(outcomes);
  });
});
