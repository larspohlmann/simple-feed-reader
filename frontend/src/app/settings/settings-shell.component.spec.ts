import { Component, signal } from '@angular/core';
import { fakeAsync, TestBed, tick } from '@angular/core/testing';
import { provideLocationMocks } from '@angular/common/testing';
import { Router, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService } from '../core/auth.service';
import { ReaderLocationService } from '../core/reader-location.service';
import { LayoutService } from '../reader/layout.service';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { MailHealthStore } from './admin/mail/mail-health.store';
import { SettingsShellComponent } from './settings-shell.component';

@Component({ template: '' })
class BlankComponent {}

describe('SettingsShellComponent', () => {
  const isWide = signal(true);
  const loadMe = jest.fn(() => of({}));
  let currentUser: object | null = null;

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideRouter([
          { path: 'settings', children: [{ path: '**', component: BlankComponent }] },
        ]),
        { provide: LayoutService, useValue: { isWide } },
        {
          provide: AuthService,
          useValue: { user: () => currentUser, loadMe, isAdmin: () => false },
        },
      ],
    }).overrideComponent(SettingsShellComponent, {
      set: { imports: [], template: '<h1>Settings</h1>', schemas: [] },
    });
    const f = TestBed.createComponent(SettingsShellComponent);
    f.detectChanges();
    return f;
  }

  beforeEach(() => {
    sessionStorage.clear();
    loadMe.mockClear();
    currentUser = null;
    isWide.set(true);
  });

  async function goTo(url: string) {
    await TestBed.inject(Router).navigateByUrl(url);
  }

  it('fetches the current user when none is loaded (deep link)', () => {
    mount();
    expect(loadMe).toHaveBeenCalled();
  });

  it('does not re-fetch an already-loaded user', () => {
    currentUser = { id: 1 };
    mount();
    expect(loadMe).not.toHaveBeenCalled();
  });

  it('leads back to the saved list from a section on desktop', async () => {
    const f = mount();
    TestBed.inject(ReaderLocationService).rememberAttemptedReaderUrl('/?tag=5');
    await goTo('/settings/preferences');
    expect(f.componentInstance.backTarget()).toBe('/?tag=5');
  });

  it('leads back to the saved article from a section on desktop', async () => {
    const f = mount();
    TestBed.inject(ReaderLocationService).rememberAttemptedReaderUrl(
      '/?tag=5&entry=12-saved-article',
    );
    await goTo('/settings/preferences');
    expect(f.componentInstance.backTarget()).toBe('/?tag=5&entry=12-saved-article');
  });

  it('falls back to the reader root when no reader location is saved', async () => {
    const f = mount();
    await goTo('/settings/preferences');
    expect(f.componentInstance.backTarget()).toBe('/');
  });

  it('leads back to the hub from a section on mobile', async () => {
    isWide.set(false);
    const f = mount();
    await goTo('/settings/preferences');
    expect(f.componentInstance.backTarget()).toBe('/settings');
    expect(f.componentInstance.backLabelKey()).toBe('settings.title');
  });

  it('leads back to the reader from the hub on mobile', async () => {
    isWide.set(false);
    const f = mount();
    TestBed.inject(ReaderLocationService).rememberAttemptedReaderUrl('/?view=unread');
    await goTo('/settings');
    expect(f.componentInstance.backTarget()).toBe('/?view=unread');
  });

  it('flags the wide sections', async () => {
    const f = mount();
    await goTo('/settings/admin/catalog');
    expect(f.componentInstance.wideSection()).toBe(true);
    await goTo('/settings/preferences');
    expect(f.componentInstance.wideSection()).toBe(false);
  });
});

describe('SettingsShellComponent reader return link', () => {
  const isWide = signal(true);

  beforeEach(() => {
    sessionStorage.clear();
    TestBed.configureTestingModule({
      imports: [SettingsShellComponent, provideTranslocoTesting()],
      providers: [
        provideRouter([
          { path: '', component: BlankComponent },
          { path: 'settings', children: [{ path: '**', component: BlankComponent }] },
        ]),
        provideLocationMocks(),
        { provide: LayoutService, useValue: { isWide } },
        {
          provide: AuthService,
          useValue: { user: () => ({ id: 1 }), loadMe: () => of({}), isAdmin: () => false },
        },
        { provide: SubscriptionsStore, useValue: { unhealthyCount: () => 0 } },
        { provide: MailHealthStore, useValue: { failureCount: () => 0, refresh: () => undefined } },
      ],
    });
  });

  it.each(['/?tag=5#reader-position', '/?tag=5&entry=12-saved-article#comments'])(
    'renders and follows the complete saved reader URL %s',
    fakeAsync((savedReaderUrl: string) => {
      const fixture = TestBed.createComponent(SettingsShellComponent);
      const router = TestBed.inject(Router);
      const readerLocation = TestBed.inject(ReaderLocationService);
      readerLocation.rememberAttemptedReaderUrl(savedReaderUrl);

      router.navigateByUrl('/settings/preferences');
      tick();
      fixture.detectChanges();

      const backLink = (fixture.nativeElement as HTMLElement).querySelector('a.back');
      expect(backLink?.getAttribute('href')).toBe(savedReaderUrl);

      backLink?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
      tick();

      expect(router.url).toBe(savedReaderUrl);
    }),
  );
});
