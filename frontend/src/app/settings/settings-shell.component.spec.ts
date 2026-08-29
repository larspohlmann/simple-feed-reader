import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService } from '../core/auth.service';
import { LayoutService } from '../reader/layout.service';
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

  it('leads back to the reader from a section on desktop', async () => {
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
    await goTo('/settings');
    expect(f.componentInstance.backTarget()).toBe('/');
  });

  it('flags the wide sections', async () => {
    const f = mount();
    await goTo('/settings/admin/catalog');
    expect(f.componentInstance.wideSection()).toBe(true);
    await goTo('/settings/preferences');
    expect(f.componentInstance.wideSection()).toBe(false);
  });
});
