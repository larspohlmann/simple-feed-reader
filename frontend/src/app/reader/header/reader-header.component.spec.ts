import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { ReaderHeaderComponent } from './reader-header.component';
import { signal } from '@angular/core';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { LayoutService } from '../layout.service';

describe('ReaderHeaderComponent', () => {
  const auth = { user: signal({ email: 'a@b.c' }), logout: jest.fn(), isAdmin: () => false };
  const layout = { isWide: signal(false), isNarrow: signal(true) } satisfies Pick<
    LayoutService,
    'isWide' | 'isNarrow'
  >;
  beforeEach(() => {
    layout.isNarrow.set(true);
    layout.isWide.set(false);
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [ReaderHeaderComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: auth },
        { provide: LayoutService, useValue: layout },
      ],
    });
  });

  function create() {
    const f = TestBed.createComponent(ReaderHeaderComponent);
    f.detectChanges();
    return f;
  }

  it('shows the app brand linking to all items and emits toggleSidebar', () => {
    const f = create();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.brand')!.textContent).toContain('simple feed reader');
    const toggle = jest.fn();
    f.componentInstance.toggleSidebar.subscribe(toggle);
    (el.querySelector('[aria-label="Toggle sidebar"]') as HTMLButtonElement).click();
    expect(toggle).toHaveBeenCalledTimes(1);
  });

  it('no longer hosts the layout/theme controls (moved to the sidebar)', () => {
    const el = create().nativeElement as HTMLElement;
    expect(el.querySelector('[aria-label="Reading layout"]')).toBeNull();
    expect(el.querySelector('[aria-label="Theme"]')).toBeNull();
  });

  // No article mode: the full-screen article rides an overlay above this bar
  // and brings its own toolbar (#128), so the bar is list chrome, always.
  it('hosts no article controls', () => {
    const el = create().nativeElement as HTMLElement;
    expect(el.querySelector('[aria-label="Previous"]')).toBeNull();
    expect(el.querySelector('[aria-label="Next"]')).toBeNull();
    expect(el.querySelector('.mode')).toBeNull();
  });

  it('renders a chip per tag with the tag-filter link and marks the active tag', () => {
    const f = create();
    f.componentRef.setInput('tags', [
      { id: 1, name: 'News', color: null, icon: null, position: 0 },
      { id: 2, name: 'Tech', color: null, icon: null, position: 1 },
    ]);
    f.componentRef.setInput('activeTagId', 2);
    f.detectChanges();
    const chips = (f.nativeElement as HTMLElement).querySelectorAll('.tagrow .chip:not(.all)');
    expect(chips.length).toBe(2);
    expect(chips[0].getAttribute('href')).toContain('tag=1');
    expect(chips[0].textContent).toContain('News');
    expect(chips[1].classList).toContain('active');
  });

  describe('the All Items pill that leads the mobile tag row', () => {
    function withTags() {
      const f = create();
      f.componentRef.setInput('tags', [
        { id: 1, name: 'News', color: null, icon: null, position: 0 },
      ]);
      f.detectChanges();
      return f;
    }

    it('comes first and links to the list with every filter cleared', () => {
      const chips = (withTags().nativeElement as HTMLElement).querySelectorAll('.tagrow .chip');
      expect(chips[0].classList).toContain('all');
      expect(chips[0].textContent).toContain('All items');
      expect(chips[0].getAttribute('href')).not.toContain('tag=');
    });

    // activeTagId alone cannot drive this: it is null for Favorites, Kept and a
    // single feed too, none of which is the All Items list.
    it('is marked active only when the shell reports the All Items selection', () => {
      const f = withTags();
      const pill = () => (f.nativeElement as HTMLElement).querySelector('.tagrow .chip.all')!;
      expect(pill().classList).not.toContain('active');

      f.componentRef.setInput('allItemsActive', true);
      f.detectChanges();
      expect(pill().classList).toContain('active');
    });

    it('does not bring the row back when the user has no tags', () => {
      const el = create().nativeElement as HTMLElement;
      expect(el.querySelector('.tagrow')).toBeNull();
    });
  });

  it('shows a Settings link, and Admin only for admins', () => {
    const f = create();
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('[aria-haspopup="menu"]') as HTMLButtonElement).click();
    f.detectChanges();
    expect(el.querySelector('a[routerLink="/settings"]')).not.toBeNull();
    expect(el.querySelector('a[routerLink="/admin/users"]')).toBeNull();
  });

  it('closes the account menu when the pointer goes down elsewhere', () => {
    const f = create();
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('[aria-haspopup="menu"]') as HTMLButtonElement).click();
    f.detectChanges();
    expect(el.querySelector('.menu')).not.toBeNull();

    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    f.detectChanges();

    expect(el.querySelector('.menu')).toBeNull();
  });

  it('shows Admin when the user is an admin', () => {
    TestBed.overrideProvider(AuthService, {
      useValue: { user: signal({ email: 'a@b.c' }), logout: jest.fn(), isAdmin: () => true },
    });
    const f = create();
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('[aria-haspopup="menu"]') as HTMLButtonElement).click();
    f.detectChanges();
    expect(el.querySelector('a[routerLink="/admin/users"]')).not.toBeNull();
  });

  describe('tap the empty middle to scroll the list to the top', () => {
    it('emits scrollTop when the middle of the bar is tapped on mobile', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;
      const fired = jest.fn();
      f.componentInstance.scrollTop.subscribe(fired);

      const spacer = el.querySelector('.tap-to-top') as HTMLButtonElement;
      expect(spacer).not.toBeNull();
      expect(spacer.getAttribute('aria-hidden')).toBe('true');
      expect(spacer.getAttribute('tabindex')).toBe('-1');
      spacer.click();
      expect(fired).toHaveBeenCalledTimes(1);
    });

    it('does not fire when the controls beside it are tapped', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;
      const fired = jest.fn();
      f.componentInstance.scrollTop.subscribe(fired);

      (el.querySelector('.menu-btn') as HTMLButtonElement).click();
      (el.querySelector('[aria-haspopup="menu"]') as HTMLButtonElement).click();
      expect(fired).not.toHaveBeenCalled();
    });

    it('is absent on a wide layout', () => {
      layout.isNarrow.set(false);
      const el = create().nativeElement as HTMLElement;
      expect(el.querySelector('.tap-to-top')).toBeNull();
    });
  });
});
