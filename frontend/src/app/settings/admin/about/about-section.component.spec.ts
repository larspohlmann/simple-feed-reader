import { TestBed } from '@angular/core/testing';
import { signal } from '@angular/core';
import { NEVER } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { AboutSectionComponent } from './about-section.component';
import { ReleaseVersion, VersionService } from '../../../core/version.service';
import { buildVersion } from '../../../../environments/version';
import { AdminApi } from '../../../admin/admin-api';

describe('AboutSectionComponent', () => {
  const bakedIn = { ...buildVersion };
  const load = jest.fn();

  function mount(api: ReleaseVersion | null, options: { unavailable?: boolean } = {}) {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        {
          provide: VersionService,
          useValue: {
            load,
            apiVersion: signal(api),
            unavailable: signal(options.unavailable ?? false),
            updateAvailable: signal(false),
            latest: signal(null),
          },
        },
        { provide: AdminApi, useValue: { updateStatus: () => NEVER } },
      ],
    });
    const fixture = TestBed.createComponent(AboutSectionComponent);
    fixture.detectChanges();
    return fixture;
  }

  function text(fixture: { nativeElement: HTMLElement }) {
    return fixture.nativeElement.textContent ?? '';
  }

  beforeEach(() => {
    load.mockReset();
  });
  afterEach(() => Object.assign(buildVersion, bakedIn));

  it('leaves the version check to the settings nav that frames it', () => {
    mount(null);
    expect(load).not.toHaveBeenCalled();
  });

  it('shows the version and commit of both halves', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const fixture = mount({
      version: 'v0.5.0-dev.3',
      commit: 'a1b2c3d',
      builtAt: '2026-07-27T10:04:11Z',
    });

    expect(text(fixture)).toContain('v0.5.0-dev.3');
    expect(text(fixture)).toContain('a1b2c3d');
    expect(text(fixture)).toContain('July 27, 2026');
  });

  it('shows no build date for a development build, which has none', () => {
    Object.assign(buildVersion, { version: 'dev', commit: 'local', builtAt: '' });
    const fixture = mount({ version: 'dev', commit: 'local', builtAt: '' });

    expect(text(fixture)).toContain('local');
    expect(text(fixture)).not.toContain('·');
  });

  it('still shows the app version when the API cannot be reached', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const fixture = mount(null, { unavailable: true });

    expect(text(fixture)).toContain('v0.5.0-dev.3');
    expect(text(fixture)).toContain('unavailable');
  });

  it('reports a stale bundle when the two releases differ', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const fixture = mount({ version: 'v0.5.0-dev.4', commit: 'e5f6a7b', builtAt: '' });

    expect(fixture.nativeElement.querySelector('.stale')).not.toBeNull();
  });

  it('says nothing when the two releases match', () => {
    Object.assign(buildVersion, { version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });
    const fixture = mount({ version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });

    expect(fixture.nativeElement.querySelector('.stale')).toBeNull();
  });

  it('does not cry stale when a development build is involved', () => {
    Object.assign(buildVersion, { version: 'dev', commit: 'local', builtAt: '' });
    const fixture = mount({ version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });

    expect(fixture.nativeElement.querySelector('.stale')).toBeNull();
  });

  it('hosts the update block once the versions are known', () => {
    const fixture = mount({ version: 'v0.5.0-dev.3', commit: 'a1b2c3d', builtAt: '' });

    expect(fixture.nativeElement.querySelector('app-self-update')).not.toBeNull();
  });

  it('renders the section as a settings group', () => {
    const element = mount(null).nativeElement;
    expect(element.querySelector('app-settings-group')).not.toBeNull();
  });

  it('links the source code to the project repository', () => {
    const fixture = mount(null);
    const source = fixture.nativeElement.querySelector(
      'a[href*="github.com/larspohlmann/simple-feed-reader"]',
    );

    expect(source).not.toBeNull();
  });
});
