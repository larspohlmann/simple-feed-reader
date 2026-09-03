import { TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { ReaderHeaderComponent } from './reader-header.component';
import { SearchFieldComponent } from '../search-field/search-field.component';
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

  describe('the mobile search bar', () => {
    it('shows the trigger, labelled, only on a narrow layout', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;
      const trigger = el.querySelector('[aria-label="Search"]');
      expect(trigger).not.toBeNull();

      layout.isNarrow.set(false);
      f.detectChanges();
      expect(el.querySelector('[aria-label="Search"]')).toBeNull();
    });

    // #408: `searchOpen` used to be plain local state, so growing past
    // NARROW_QUERY mid-search left it stuck true — the mobile bar (with its own
    // `/` listener) stayed mounted alongside the sidebar's instance.
    it('closes the bar when the layout stops being narrow', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;

      (el.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      f.detectChanges();
      expect(f.componentInstance.searchOpen()).toBe(true);

      layout.isNarrow.set(false);
      f.detectChanges();

      expect(f.componentInstance.searchOpen()).toBe(false);
      expect(el.querySelector('app-search-field')).toBeNull();
    });

    it('does not reopen on its own when the layout narrows again', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;

      (el.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      f.detectChanges();

      layout.isNarrow.set(false);
      f.detectChanges();
      layout.isNarrow.set(true);
      f.detectChanges();

      expect(f.componentInstance.searchOpen()).toBe(false);
    });

    // The field is mounted twice — here and in the sidebar — wired by hand each
    // time. The sidebar's carried `[term]`; this one did not, so a narrow layout
    // showed results for `?q=` above an empty box after a reload or Back.
    it('opens the bar showing the term the route already carries', () => {
      const f = create();
      f.componentRef.setInput('searchTerm', 'daft punk');
      f.detectChanges();
      const el = f.nativeElement as HTMLElement;

      (el.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      f.detectChanges();

      expect((el.querySelector('app-search-field input') as HTMLInputElement).value).toBe(
        'daft punk',
      );
    });

    it('covers the header with the field on click, hiding the brand and account', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;

      (el.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      f.detectChanges();

      expect(f.componentInstance.searchOpen()).toBe(true);
      expect(el.querySelector('.brand')).toBeNull();
      expect(el.querySelector('[aria-haspopup="menu"]')).toBeNull();
      expect(el.querySelector('app-search-field')).not.toBeNull();
    });

    it('hides the tag row while the bar is open (#486)', () => {
      const f = create();
      f.componentRef.setInput('tags', [
        { id: 1, name: 'News', color: null, icon: null, position: 0 },
      ]);
      f.detectChanges();
      const el = f.nativeElement as HTMLElement;
      // The row is there before the search opens…
      expect(el.querySelector('.tagrow')).not.toBeNull();

      (el.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      f.detectChanges();

      // …and gone once it does: the pinned search bar stands alone rather than
      // sharing the top with a tag-filter row that duplicates a different axis.
      expect(el.querySelector('.tagrow')).toBeNull();
    });

    it('forwards the settled term from the field as its own search output', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;
      const fired: string[] = [];
      f.componentInstance.search.subscribe((term) => fired.push(term));

      (el.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      f.detectChanges();
      // The search-field component owns the debounce and the length floor; this
      // header only forwards whatever it settles on.
      const field = f.debugElement.query(By.directive(SearchFieldComponent))
        .componentInstance as SearchFieldComponent;
      field.search.emit('angular');
      expect(fired).toEqual(['angular']);
    });

    it('forwards searchLoading to the mobile bar field', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;
      f.componentRef.setInput('searchLoading', true);

      (el.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      f.detectChanges();

      const field = f.debugElement.query(By.directive(SearchFieldComponent))
        .componentInstance as SearchFieldComponent;
      expect(field.loading()).toBe(true);
    });

    it('restores the brand and account when the close control is clicked', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;

      (el.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      f.detectChanges();
      (el.querySelector('[aria-label="Close search"]') as HTMLButtonElement).click();
      f.detectChanges();

      expect(f.componentInstance.searchOpen()).toBe(false);
      expect(el.querySelector('.brand')).not.toBeNull();
      expect(el.querySelector('[aria-haspopup="menu"]')).not.toBeNull();
    });

    it("carries a single ✕: the field's own, with none beside it (#550)", () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;

      (el.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      f.detectChanges();

      // The open bar replaces the whole header, so every button in it belongs
      // to the search. Exactly one, and it is the field's own.
      const buttons = Array.from(el.querySelectorAll('header button'));
      expect(buttons).toHaveLength(1);
      expect(buttons[0].closest('app-search-field')).not.toBeNull();
    });

    it('stays open on a pointerdown outside the bar, so scrolling the results does not dismiss it (#486)', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;

      (el.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      f.detectChanges();
      expect(f.componentInstance.searchOpen()).toBe(true);

      // On a phone the results list is "outside" the bar; a scroll touch on it
      // fires pointerdown. The bar must survive that — it closes only on its own
      // ✕ or on the two-step Escape (both covered below).
      document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
      f.detectChanges();

      expect(f.componentInstance.searchOpen()).toBe(true);
    });

    it('clears without closing on a first Escape over a non-empty field', () => {
      // The two-step contract end to end: the first Escape only clears the
      // text (proven at the field level already), and here specifically it
      // must NOT also close the bar at the header level.
      const f = create();
      const el = f.nativeElement as HTMLElement;

      (el.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      f.detectChanges();

      const input = el.querySelector('app-search-field input') as HTMLInputElement;
      input.value = 'cats';
      input.dispatchEvent(new Event('input'));
      f.detectChanges();

      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      f.detectChanges();

      expect(input.value).toBe('');
      expect(f.componentInstance.searchOpen()).toBe(true);
    });

    it('closes when the field reports Escape on an already-empty field', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;

      (el.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      f.detectChanges();

      const input = el.querySelector('app-search-field input') as HTMLInputElement;
      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      f.detectChanges();

      expect(f.componentInstance.searchOpen()).toBe(false);
    });

    it('moves focus into the field on open and back to the trigger on close, twice over', () => {
      const f = create();
      const el = f.nativeElement as HTMLElement;

      function trigger(): HTMLButtonElement {
        return el.querySelector('[aria-label="Search"]') as HTMLButtonElement;
      }
      function fieldInput(): HTMLInputElement {
        return el.querySelector('app-search-field input') as HTMLInputElement;
      }
      function closeButton(): HTMLButtonElement {
        return el.querySelector('[aria-label="Close search"]') as HTMLButtonElement;
      }

      trigger().click();
      f.detectChanges();
      expect(document.activeElement).toBe(fieldInput());

      closeButton().click();
      f.detectChanges();
      expect(document.activeElement).toBe(trigger());

      trigger().click();
      f.detectChanges();
      expect(document.activeElement).toBe(fieldInput());

      closeButton().click();
      f.detectChanges();
      expect(document.activeElement).toBe(trigger());
    });
  });
});
