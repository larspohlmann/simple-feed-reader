import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { API_BASE_URL } from '../../core/api';
import { AuthService, CurrentUser } from '../../core/auth/auth.service';
import { VersionService } from '../../core/version.service';
import { LayoutService } from '../layout.service';
import { SidebarFootComponent } from './sidebar-foot.component';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { buildVersion } from '../../../environments/version';

const account = (trialEndsAt: string | null): CurrentUser => ({
  id: 1,
  email: 'me@x',
  roles: ['ROLE_USER'],
  status: 'active',
  createdAt: '2026-01-01T00:00:00Z',
  locale: 'en',
  trialEndsAt,
  preferences: {
    scrapeFallbackEnabled: false,
    digest: {
      enabled: false,
      cadence: 'daily',
      sendHour: 8,
      weekday: 1,
      format: 'html',
      timezone: 'UTC',
    },
    passkeyOfferAnswered: true,
    magazineStyle: 'boxed',
  },
  ai: { ready: false, model: null },
  mail: { enabled: true },
  emailVerified: true,
});

const inDays = (days: number): string => new Date(Date.now() + days * 86_400_000).toISOString();

function mount(
  over: Partial<{ user: CurrentUser | null; coarse: boolean; organising: boolean }> = {},
) {
  TestBed.configureTestingModule({
    imports: [SidebarFootComponent, provideTranslocoTesting()],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: API_BASE_URL, useValue: 'https://api.test' },
      { provide: AuthService, useValue: { user: signal(over.user ?? account(null)) } },
      {
        provide: LayoutService,
        useValue: { isCoarse: signal(over.coarse ?? false), isNarrow: signal(false) },
      },
    ],
  });
  const fixture = TestBed.createComponent(SidebarFootComponent);
  fixture.componentRef.setInput('organising', over.organising ?? false);
  fixture.detectChanges();
  return fixture;
}

describe('SidebarFootComponent', () => {
  it('shows the running build as a link into settings', () => {
    const version = (mount().nativeElement as HTMLElement).querySelector('.version');

    expect(version?.textContent?.trim()).toBe(buildVersion.version);
    expect(version?.getAttribute('href')).toBe('/settings');
  });

  it('shows an update badge linking to the release notes when an update is available', () => {
    const fixture = mount();
    const versions = TestBed.inject(VersionService);
    versions.latest.set({ version: 'v9.9.9', notesUrl: 'https://github.test/releases/tag/v9.9.9' });
    versions.updateAvailable.set(true);
    fixture.detectChanges();

    const badge = (fixture.nativeElement as HTMLElement).querySelector('.update-badge');
    expect(badge).not.toBeNull();
    expect(badge?.textContent).toContain('v9.9.9');
    expect(badge?.getAttribute('href')).toBe('https://github.test/releases/tag/v9.9.9');
    expect(badge?.getAttribute('target')).toBe('_blank');
    expect(badge?.getAttribute('rel')).toBe('noopener noreferrer');
  });

  it('keeps the update badge out of the version and feedback row', () => {
    const fixture = mount();
    const versions = TestBed.inject(VersionService);
    versions.latest.set({ version: 'v9.9.9', notesUrl: 'https://github.test/releases/tag/v9.9.9' });
    versions.updateAvailable.set(true);
    fixture.detectChanges();

    const host = fixture.nativeElement as HTMLElement;
    const badge = host.querySelector('.update-badge');
    const meta = host.querySelector('.meta');
    expect(badge?.parentElement).toBe(host);
    expect(badge?.nextElementSibling).toBe(meta);
    expect(Array.from(meta?.children ?? []).map((link) => link.className)).toEqual([
      'version',
      'feedback',
    ]);
  });

  it('shows no update badge when the running build is current', () => {
    const fixture = mount();
    const versions = TestBed.inject(VersionService);
    versions.updateAvailable.set(false);
    versions.latest.set(null);
    fixture.detectChanges();

    expect((fixture.nativeElement as HTMLElement).querySelector('.update-badge')).toBeNull();
  });

  it('shows the trial countdown when a trial is active', () => {
    const element = mount({ user: account(inDays(5)) }).nativeElement.querySelector('.trial');
    expect(element?.textContent).toContain('5');
    // Five days is outside the "ending soon" window, so it is not emphasised.
    expect(element?.classList.contains('soon')).toBe(false);
  });

  it('emphasises the countdown in the last three days of the trial', () => {
    const element = mount({ user: account(inDays(2)) }).nativeElement.querySelector('.trial');
    expect(element?.classList.contains('soon')).toBe(true);
  });

  it('hides the trial countdown when there is no trial', () => {
    expect(mount({ user: account(null) }).nativeElement.querySelector('.trial')).toBeNull();
  });

  it('hides the trial countdown when the trial is already past', () => {
    expect(mount({ user: account(inDays(-1)) }).nativeElement.querySelector('.trial')).toBeNull();
  });

  it('hides the Organise switch on fine pointers', () => {
    expect(mount({ coarse: false }).nativeElement.querySelector('.organise')).toBeNull();
  });

  it('shows the Organise switch on coarse pointers and flips the model on click', () => {
    const fixture = mount({ coarse: true });
    const organiseSwitch = (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(
      '.organise',
    )!;
    expect(organiseSwitch.getAttribute('role')).toBe('switch');
    expect(organiseSwitch.getAttribute('aria-checked')).toBe('false');

    organiseSwitch.click();
    fixture.detectChanges();

    expect(fixture.componentInstance.organising()).toBe(true);
    expect(organiseSwitch.getAttribute('aria-checked')).toBe('true');
  });

  it('hides the brightness control, view controls and trial line while organising', () => {
    const element = mount({ coarse: true, organising: true, user: account(inDays(5)) })
      .nativeElement as HTMLElement;
    expect(element.querySelector('app-brightness-control')).toBeNull();
    expect(element.querySelector('app-view-controls')).toBeNull();
    expect(element.querySelector('.trial')).toBeNull();
    // The version link stays visible even while organising.
    expect(element.querySelector('.version')).not.toBeNull();
  });

  it('keeps the foot order: organise, brightness, view controls, trial, meta', () => {
    const element = mount({ coarse: true, user: account(inDays(5)) }).nativeElement as HTMLElement;
    const order = Array.from(element.children).map((child) => child.classList[0]);
    expect(order).toEqual(['organise', 'brightness', 'controls', 'trial', 'meta']);
  });

  it('shows the brightness control on fine pointers too', () => {
    const element = mount({ coarse: false }).nativeElement as HTMLElement;
    expect(element.querySelector('app-brightness-control')).not.toBeNull();
  });

  it('links Feedback to the public issue tracker in a new tab', () => {
    const feedback = (mount().nativeElement as HTMLElement).querySelector('.feedback');

    expect(feedback?.textContent?.trim()).toBe('Feedback');
    expect(feedback?.getAttribute('href')).toBe(
      'https://github.com/larspohlmann/simple-feed-reader/issues',
    );
    expect(feedback?.getAttribute('target')).toBe('_blank');
    expect(feedback?.getAttribute('rel')).toBe('noopener noreferrer');
  });
});
