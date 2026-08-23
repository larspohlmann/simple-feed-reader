import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AboutSectionComponent } from './about-section.component';
import { ReleaseVersion, VersionService } from '../core/version.service';
import { buildVersion } from '../../environments/version';

describe('AboutSectionComponent', () => {
  const bakedIn = { ...buildVersion };
  const load = jest.fn();

  function mount(api: ReleaseVersion | null, unavailable = false) {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        {
          provide: VersionService,
          useValue: { load, apiVersion: signal(api), unavailable: signal(unavailable) },
        },
      ],
    });
    const f = TestBed.createComponent(AboutSectionComponent);
    f.detectChanges();
    return f;
  }

  function text(fixture: { nativeElement: HTMLElement }) {
    return fixture.nativeElement.textContent ?? '';
  }

  async function renderWhileLoading(): Promise<HTMLElement> {
    return mount(null).nativeElement;
  }

  async function renderLoaded(): Promise<HTMLElement> {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    return mount({ version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' }).nativeElement;
  }

  beforeEach(() => load.mockReset());
  afterEach(() => Object.assign(buildVersion, bakedIn));

  it('asks the API which build it is running', () => {
    mount(null);
    expect(load).toHaveBeenCalled();
  });

  it('shows the version and commit of both halves', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const f = mount({
      version: 'v0.5.0-dev.3',
      commit: 'a1b2c3d',
      builtAt: '2026-07-27T10:04:11Z',
    });

    expect(text(f)).toContain('v0.5.0-dev.3');
    expect(text(f)).toContain('a1b2c3d');
    // Localised through the same helper the rest of the app uses, not DatePipe.
    expect(text(f)).toContain('July 27, 2026');
  });

  it('shows no build date for a development build, which has none', () => {
    Object.assign(buildVersion, { version: 'dev', commit: 'local', builtAt: '' });
    const f = mount({ version: 'dev', commit: 'local', builtAt: '' });

    expect(text(f)).toContain('local');
    expect(text(f)).not.toContain('·');
  });

  it('still shows the app version when the API cannot be reached', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const f = mount(null, true);

    expect(text(f)).toContain('v0.5.0-dev.3');
    expect(text(f)).toContain('unavailable');
  });

  it('reports a stale bundle when the two releases differ', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const f = mount({ version: 'v0.5.0-dev.4', commit: 'e5f6a7b', builtAt: '' });

    expect(f.nativeElement.querySelector('.stale')).not.toBeNull();
  });

  it('says nothing when the two releases match', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const f = mount({ version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });

    expect(f.nativeElement.querySelector('.stale')).toBeNull();
  });

  /**
   * A development build on either side is not evidence of a stale cache — it is
   * an `ng serve` talking to a Docker backend, which is the normal dev setup.
   */
  it('does not cry stale when a development build is involved', () => {
    Object.assign(buildVersion, { version: 'dev', commit: 'local', builtAt: '' });
    const f = mount({ version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });

    expect(f.nativeElement.querySelector('.stale')).toBeNull();
  });

  it('shows a spinner while the version is loading', async () => {
    const el = await renderWhileLoading();
    expect(el.querySelector('app-spinner')).not.toBeNull();
  });

  it('renders the section as a settings group', async () => {
    const el = await renderLoaded();
    expect(el.querySelector('app-settings-group')).not.toBeNull();
  });
});
