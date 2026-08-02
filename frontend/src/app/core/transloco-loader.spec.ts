import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { HttpTranslocoLoader } from './transloco-loader';
import { buildVersion } from '../../environments/version';

describe('HttpTranslocoLoader', () => {
  let loader: HttpTranslocoLoader;
  let ctrl: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [HttpTranslocoLoader, provideHttpClient(), provideHttpClientTesting()],
    });
    loader = TestBed.inject(HttpTranslocoLoader);
    ctrl = TestBed.inject(HttpTestingController);
  });

  afterEach(() => ctrl.verify());

  // The app is served both at the domain root (Docker) and under a /reader
  // subpath (Strato). A leading slash would pin the request to the root and
  // 404 under the subpath; a relative URL resolves against <base href>.
  it('requests the dictionary relative to the base href', () => {
    loader.getTranslation('de').subscribe();

    const req = ctrl.expectOne((request) => request.url === 'i18n/de.json');
    expect(req.request.url.startsWith('/')).toBe(false);
    req.flush({});
  });

  // The dictionaries live at a path that never changes, so a browser that
  // cached the previous release keeps serving it and renders every key added
  // since as its raw name (#141). The version turns each release into a URL no
  // cache can already hold.
  it('carries the build version, so a new release cannot hit a cached copy', () => {
    loader.getTranslation('en').subscribe();

    const req = ctrl.expectOne(`i18n/en.json?v=${buildVersion.version}`);
    expect(req.request.params.get('v')).toBe(buildVersion.version);
    req.flush({});
  });
});
