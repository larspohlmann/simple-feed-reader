import { signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { AuthService } from '../core/auth.service';
import { SubscriptionsStore } from '../reader/subscriptions.store';
import { SettingsNavComponent } from './settings-nav.component';
import { SETTINGS_SECTIONS } from './settings-sections';

describe('SettingsNavComponent', () => {
  function mount(roles: string[], variant: 'rail' | 'hub' = 'rail', unhealthyCount = 0) {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideRouter([]),
        {
          provide: AuthService,
          useValue: { user: () => ({ roles }), isAdmin: () => roles.includes('ROLE_ADMIN') },
        },
        { provide: SubscriptionsStore, useValue: { unhealthyCount: signal(unhealthyCount) } },
      ],
    });
    const f = TestBed.createComponent(SettingsNavComponent);
    f.componentRef.setInput('variant', variant);
    f.detectChanges();
    return f;
  }

  it('renders a link per general section for a plain user, and no admin group', () => {
    const f = mount(['ROLE_USER']);
    const links = f.nativeElement.querySelectorAll('a');
    const generalCount = SETTINGS_SECTIONS.filter((s) => s.group === 'general').length;
    expect(links.length).toBe(generalCount);
  });

  it('renders the admin group for an admin', () => {
    const f = mount(['ROLE_USER', 'ROLE_ADMIN']);
    const links = [...f.nativeElement.querySelectorAll('a')] as HTMLAnchorElement[];
    expect(links.length).toBe(SETTINGS_SECTIONS.length);
    expect(links.some((a) => a.getAttribute('href') === '/settings/admin/catalog')).toBe(true);
  });

  it('carries the variant as a host-level class', () => {
    const f = mount(['ROLE_USER'], 'hub');
    expect(f.nativeElement.querySelector('nav').classList).toContain('hub');
  });

  it('badges the Organise entry with the unhealthy-feed count, and no other entry', () => {
    const f = mount(['ROLE_USER'], 'rail', 2);
    const links = [...f.nativeElement.querySelectorAll('a')] as HTMLAnchorElement[];
    const organise = links.find((a) => a.getAttribute('href') === '/settings/organise');
    const preferences = links.find((a) => a.getAttribute('href') === '/settings/preferences');
    expect(organise?.querySelector('.badge')?.textContent?.trim()).toBe('2');
    expect(preferences?.querySelector('.badge')).toBeNull();
  });

  it('renders no badge anywhere when there are no unhealthy feeds', () => {
    const f = mount(['ROLE_USER'], 'rail', 0);
    expect(f.nativeElement.querySelector('.badge')).toBeNull();
  });
});
