import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService } from '../core/auth.service';
import { LayoutService } from '../reader/layout.service';
import { SettingsHubComponent } from './settings-hub.component';

@Component({ template: '' })
class BlankComponent {}

describe('SettingsHubComponent', () => {
  const isWide = signal(false);

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideRouter([
          { path: 'settings', children: [{ path: '**', component: BlankComponent }] },
        ]),
        { provide: LayoutService, useValue: { isWide } },
        { provide: AuthService, useValue: { user: () => null, isAdmin: () => false } },
      ],
    });
    const f = TestBed.createComponent(SettingsHubComponent);
    f.detectChanges();
    return f;
  }

  it('renders the hub nav on a narrow viewport and stays put', async () => {
    isWide.set(false);
    const f = mount();
    await f.whenStable();
    expect(f.nativeElement.querySelector('app-settings-nav')).not.toBeNull();
    expect(TestBed.inject(Router).url).toBe('/');
  });

  it('forwards to the first section on a wide viewport', async () => {
    isWide.set(true);
    const f = mount();
    await f.whenStable();
    expect(TestBed.inject(Router).url).toBe('/settings/organise');
  });

  it('forwards when the viewport grows past the breakpoint while open', async () => {
    isWide.set(false);
    const f = mount();
    await f.whenStable();
    isWide.set(true);
    f.detectChanges();
    await f.whenStable();
    expect(TestBed.inject(Router).url).toBe('/settings/organise');
  });
});
