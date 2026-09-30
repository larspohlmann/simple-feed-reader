import { Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService } from '../core/auth.service';
import { LayoutService } from '../reader/layout.service';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { MailHealthStore } from './admin/mail/mail-health.store';
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
        { provide: SubscriptionsStore, useValue: { unhealthyCount: signal(0) } },
        { provide: MailHealthStore, useValue: { failureCount: signal(0), refresh: jest.fn() } },
      ],
    });
    const fixture = TestBed.createComponent(SettingsHubComponent);
    fixture.detectChanges();
    return fixture;
  }

  it('renders the hub nav on a narrow viewport and stays put', async () => {
    isWide.set(false);
    const fixture = mount();
    await fixture.whenStable();
    expect(fixture.nativeElement.querySelector('app-settings-nav')).not.toBeNull();
    expect(TestBed.inject(Router).url).toBe('/');
  });

  it('forwards to the first section on a wide viewport', async () => {
    isWide.set(true);
    const fixture = mount();
    await fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/settings/organise');
  });

  it('forwards when the viewport grows past the breakpoint while open', async () => {
    isWide.set(false);
    const fixture = mount();
    await fixture.whenStable();
    isWide.set(true);
    fixture.detectChanges();
    await fixture.whenStable();
    expect(TestBed.inject(Router).url).toBe('/settings/organise');
  });
});
