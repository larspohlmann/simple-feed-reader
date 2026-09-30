import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService } from '../core/auth/auth.service';
import { SubscriptionsStore } from '../reader/state/subscriptions.store';
import { MailHealthStore } from './admin/mail/mail-health.store';
import { SettingsNavComponent } from './settings-nav.component';
import { SETTINGS_SECTIONS } from './settings-sections';

describe('SettingsNavComponent', () => {
  function mount(
    roles: string[],
    variant: 'rail' | 'hub' = 'rail',
    unhealthyCount = 0,
    mailFailureCount = 0,
  ) {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideRouter([]),
        {
          provide: AuthService,
          useValue: { user: () => ({ roles }), isAdmin: () => roles.includes('ROLE_ADMIN') },
        },
        { provide: SubscriptionsStore, useValue: { unhealthyCount: signal(unhealthyCount) } },
        {
          provide: MailHealthStore,
          useValue: { failureCount: signal(mailFailureCount), refresh: jest.fn() },
        },
      ],
    });
    const fixture = TestBed.createComponent(SettingsNavComponent);
    fixture.componentRef.setInput('variant', variant);
    fixture.detectChanges();
    return fixture;
  }

  it('renders a link per general section for a plain user, and no admin group', () => {
    const fixture = mount(['ROLE_USER']);
    const links = fixture.nativeElement.querySelectorAll('a');
    const generalCount = SETTINGS_SECTIONS.filter((section) => section.group === 'general').length;
    expect(links.length).toBe(generalCount);
  });

  it('renders the admin group for an admin', () => {
    const fixture = mount(['ROLE_USER', 'ROLE_ADMIN']);
    const links = [...fixture.nativeElement.querySelectorAll('a')] as HTMLAnchorElement[];
    expect(links.length).toBe(SETTINGS_SECTIONS.length);
    expect(links.some((anchor) => anchor.getAttribute('href') === '/settings/admin/catalog')).toBe(
      true,
    );
  });

  it('carries the variant as a host-level class', () => {
    const fixture = mount(['ROLE_USER'], 'hub');
    expect(fixture.nativeElement.querySelector('nav').classList).toContain('hub');
  });

  it('badges the Organise entry with the unhealthy-feed count, and no other entry', () => {
    const fixture = mount(['ROLE_USER'], 'rail', 2);
    const links = [...fixture.nativeElement.querySelectorAll('a')] as HTMLAnchorElement[];
    const organise = links.find((anchor) => anchor.getAttribute('href') === '/settings/organise');
    const preferences = links.find(
      (anchor) => anchor.getAttribute('href') === '/settings/preferences',
    );
    expect(organise?.querySelector('.badge')?.textContent?.trim()).toBe('2');
    expect(preferences?.querySelector('.badge')).toBeNull();
  });

  it('renders no badge anywhere when there are no unhealthy feeds', () => {
    const fixture = mount(['ROLE_USER'], 'rail', 0);
    expect(fixture.nativeElement.querySelector('.badge')).toBeNull();
  });

  it('badges the admin Outgoing mail entry with the mail-failure count for an admin', () => {
    const fixture = mount(['ROLE_USER', 'ROLE_ADMIN'], 'rail', 0, 3);
    const links = [...fixture.nativeElement.querySelectorAll('a')] as HTMLAnchorElement[];
    const mail = links.find((anchor) => anchor.getAttribute('href') === '/settings/admin/mail');
    expect(mail?.querySelector('.badge')?.textContent?.trim()).toBe('3');
  });

  it('shows no mail badge for a non-admin, even with a nonzero failure count', () => {
    const fixture = mount(['ROLE_USER'], 'rail', 0, 3);
    expect(fixture.nativeElement.querySelector('.badge')).toBeNull();
  });

  it('shows no mail badge for an admin with a zero failure count', () => {
    const fixture = mount(['ROLE_USER', 'ROLE_ADMIN'], 'rail', 0, 0);
    const links = [...fixture.nativeElement.querySelectorAll('a')] as HTMLAnchorElement[];
    const mail = links.find((anchor) => anchor.getAttribute('href') === '/settings/admin/mail');
    expect(mail?.querySelector('.badge')).toBeNull();
  });
});
