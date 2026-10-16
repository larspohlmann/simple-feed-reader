import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { escapeJsonSlashesInterceptor } from './escape-json-slashes';

describe('escapeJsonSlashesInterceptor', () => {
  let http: HttpClient;
  let backend: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([escapeJsonSlashesInterceptor])),
        provideHttpClientTesting(),
      ],
    });
    http = TestBed.inject(HttpClient);
    backend = TestBed.inject(HttpTestingController);
  });

  afterEach(() => backend.verify());

  it('writes every slash in a JSON body as \\/ and sends it as JSON', () => {
    http.post('/api/subscriptions', { url: 'https://bsky.app/profile/x/rss' }).subscribe();

    const sent = backend.expectOne('/api/subscriptions').request;
    expect(sent.body).toBe('{"url":"https:\\/\\/bsky.app\\/profile\\/x\\/rss"}');
    expect(sent.headers.get('Content-Type')).toBe('application/json');
  });

  it('sends a body that parses back to the original, a literal backslash before a slash included', () => {
    const body = { path: 'a\\/b', feeds: [{ url: 'https://mastodon.social/@x.rss' }] };
    http.put('/api/feeds', body).subscribe();

    const sent = backend.expectOne('/api/feeds').request;
    expect(JSON.parse(sent.body as string)).toEqual(body);
  });

  it('keeps a Content-Type the caller named', () => {
    const headers = { 'Content-Type': 'application/merge-patch+json' };
    http.patch('/api/me', { avatar: 'https://bsky.social/a.png' }, { headers }).subscribe();

    const sent = backend.expectOne('/api/me').request;
    expect(sent.headers.get('Content-Type')).toBe('application/merge-patch+json');
    expect(sent.body).toBe('{"avatar":"https:\\/\\/bsky.social\\/a.png"}');
  });

  it.each([
    ['an XML string', '<outline xmlUrl="https://bsky.app/x/rss"/>'],
    ['a Blob', new Blob(['{"url":"https://bsky.app/x"}'])],
    ['FormData', new FormData()],
    ['URLSearchParams', new URLSearchParams({ url: 'https://bsky.app/x' })],
  ])('leaves %s untouched', (_kind, body) => {
    http.post('/api/opml/import', body).subscribe();

    expect(backend.expectOne('/api/opml/import').request.body).toBe(body);
  });

  it('leaves a bodiless GET untouched', () => {
    http.get('/api/subscriptions').subscribe();

    const sent = backend.expectOne('/api/subscriptions').request;
    expect(sent.body).toBeNull();
    expect(sent.headers.has('Content-Type')).toBe(false);
  });
});
