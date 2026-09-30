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
    const fixture = TestBed.createComponent(ReaderHeaderComponent);
    fixture.detectChanges();
    return fixture;
  }

  it('shows the app brand linking to all items and emits toggleSidebar', () => {
    const fixture = create();
    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.brand')!.textContent).toContain('simple feed reader');
    const toggle = jest.fn();
    fixture.componentInstance.toggleSidebar.subscribe(toggle);
    (element.querySelector('[aria-label="Toggle sidebar"]') as HTMLButtonElement).click();
    expect(toggle).toHaveBeenCalledTimes(1);
  });

  it('no longer hosts the layout/theme controls (moved to the sidebar)', () => {
    const element = create().nativeElement as HTMLElement;
    expect(element.querySelector('[aria-label="Reading layout"]')).toBeNull();
    expect(element.querySelector('[aria-label="Theme"]')).toBeNull();
  });

  // No article mode: the full-screen article rides an overlay above this bar
  // and brings its own toolbar (#128), so the bar is list chrome, always.
  it('hosts no article controls', () => {
    const element = create().nativeElement as HTMLElement;
    expect(element.querySelector('[aria-label="Previous"]')).toBeNull();
    expect(element.querySelector('[aria-label="Next"]')).toBeNull();
    expect(element.querySelector('.mode')).toBeNull();
  });

  it('renders a chip per tag with the tag-filter link and marks the active tag', () => {
    const fixture = create();
    fixture.componentRef.setInput('tags', [
      { id: 1, name: 'News', color: null, icon: null, position: 0 },
      { id: 2, name: 'Tech', color: null, icon: null, position: 1 },
    ]);
    fixture.componentRef.setInput('activeTagId', 2);
    fixture.detectChanges();
    const chips = (fixture.nativeElement as HTMLElement).querySelectorAll(
      '.tagrow .chip:not(.all)',
    );
    expect(chips.length).toBe(2);
    expect(chips[0].getAttribute('href')).toContain('tag=1');
    expect(chips[0].textContent).toContain('News');
    expect(chips[1].classList).toContain('active');
  });

  describe('the All Items pill that leads the mobile tag row', () => {
    function withTags() {
      const fixture = create();
      fixture.componentRef.setInput('tags', [
        { id: 1, name: 'News', color: null, icon: null, position: 0 },
      ]);
      fixture.detectChanges();
      return fixture;
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
      const fixture = withTags();
      const pill = () => (fixture.nativeElement as HTMLElement).querySelector('.tagrow .chip.all')!;
      expect(pill().classList).not.toContain('active');

      fixture.componentRef.setInput('allItemsActive', true);
      fixture.detectChanges();
      expect(pill().classList).toContain('active');
    });

    it('does not bring the row back when the user has no tags', () => {
      const element = create().nativeElement as HTMLElement;
      expect(element.querySelector('.tagrow')).toBeNull();
    });
  });

  it('shows a Settings link, and Admin only for admins', () => {
    const fixture = create();
    const element = fixture.nativeElement as HTMLElement;
    (element.querySelector('[aria-haspopup="menu"]') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(element.querySelector('a[routerLink="/settings"]')).not.toBeNull();
    expect(element.querySelector('a[routerLink="/admin/users"]')).toBeNull();
  });

  it('closes the account menu when the pointer goes down elsewhere', () => {
    const fixture = create();
    const element = fixture.nativeElement as HTMLElement;
    (element.querySelector('[aria-haspopup="menu"]') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(element.querySelector('.menu')).not.toBeNull();

    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    fixture.detectChanges();

    expect(element.querySelector('.menu')).toBeNull();
  });

  it('shows Admin when the user is an admin', () => {
    TestBed.overrideProvider(AuthService, {
      useValue: { user: signal({ email: 'a@b.c' }), logout: jest.fn(), isAdmin: () => true },
    });
    const fixture = create();
    const element = fixture.nativeElement as HTMLElement;
    (element.querySelector('[aria-haspopup="menu"]') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(element.querySelector('a[routerLink="/admin/users"]')).not.toBeNull();
  });

  describe('tap the empty middle to scroll the list to the top', () => {
    it('emits scrollTop when the middle of the bar is tapped on mobile', () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;
      const fired = jest.fn();
      fixture.componentInstance.scrollTop.subscribe(fired);

      const spacer = element.querySelector('.tap-to-top') as HTMLButtonElement;
      expect(spacer).not.toBeNull();
      expect(spacer.getAttribute('aria-hidden')).toBe('true');
      expect(spacer.getAttribute('tabindex')).toBe('-1');
      spacer.click();
      expect(fired).toHaveBeenCalledTimes(1);
    });

    it('does not fire when the controls beside it are tapped', () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;
      const fired = jest.fn();
      fixture.componentInstance.scrollTop.subscribe(fired);

      (element.querySelector('.menu-btn') as HTMLButtonElement).click();
      (element.querySelector('[aria-haspopup="menu"]') as HTMLButtonElement).click();
      expect(fired).not.toHaveBeenCalled();
    });

    it('is absent on a wide layout', () => {
      layout.isNarrow.set(false);
      const element = create().nativeElement as HTMLElement;
      expect(element.querySelector('.tap-to-top')).toBeNull();
    });
  });

  describe('the mobile search bar', () => {
    it('shows the trigger, labelled, only on a narrow layout', () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;
      const trigger = element.querySelector('[aria-label="Search"]');
      expect(trigger).not.toBeNull();

      layout.isNarrow.set(false);
      fixture.detectChanges();
      expect(element.querySelector('[aria-label="Search"]')).toBeNull();
    });

    // #408: growing past NARROW_QUERY mid-search closes the mobile bar, whose
    // own `/` listener would otherwise stay mounted beside the sidebar's.
    it('closes the bar when the layout stops being narrow', () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;

      (element.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(fixture.componentInstance.searchOpen()).toBe(true);

      layout.isNarrow.set(false);
      fixture.detectChanges();

      expect(fixture.componentInstance.searchOpen()).toBe(false);
      expect(element.querySelector('app-search-field')).toBeNull();
    });

    it('does not reopen on its own when the layout narrows again', () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;

      (element.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      fixture.detectChanges();

      layout.isNarrow.set(false);
      fixture.detectChanges();
      layout.isNarrow.set(true);
      fixture.detectChanges();

      expect(fixture.componentInstance.searchOpen()).toBe(false);
    });

    // The field is mounted twice — here and in the sidebar — wired by hand each
    // time. The sidebar's carried `[term]`; this one did not, so a narrow layout
    // showed results for `?q=` above an empty box after a reload or Back.
    it('opens the bar showing the term the route already carries', () => {
      const fixture = create();
      fixture.componentRef.setInput('searchTerm', 'daft punk');
      fixture.detectChanges();
      const element = fixture.nativeElement as HTMLElement;

      (element.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      fixture.detectChanges();

      expect((element.querySelector('app-search-field input') as HTMLInputElement).value).toBe(
        'daft punk',
      );
    });

    it('covers the header with the field on click, hiding the brand and account', () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;

      (element.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      fixture.detectChanges();

      expect(fixture.componentInstance.searchOpen()).toBe(true);
      expect(element.querySelector('.brand')).toBeNull();
      expect(element.querySelector('[aria-haspopup="menu"]')).toBeNull();
      expect(element.querySelector('app-search-field')).not.toBeNull();
    });

    it('hides the tag row while the bar is open (#486)', () => {
      const fixture = create();
      fixture.componentRef.setInput('tags', [
        { id: 1, name: 'News', color: null, icon: null, position: 0 },
      ]);
      fixture.detectChanges();
      const element = fixture.nativeElement as HTMLElement;
      // The row is there before the search opens…
      expect(element.querySelector('.tagrow')).not.toBeNull();

      (element.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      fixture.detectChanges();

      // …and gone once it does: the pinned search bar stands alone rather than
      // sharing the top with a tag-filter row that duplicates a different axis.
      expect(element.querySelector('.tagrow')).toBeNull();
    });

    it('forwards the settled term from the field as its own search output', () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;
      const fired: string[] = [];
      fixture.componentInstance.search.subscribe((term) => fired.push(term));

      (element.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      // The search-field component owns the debounce and the length floor; this
      // header only forwards whatever it settles on.
      const field = fixture.debugElement.query(By.directive(SearchFieldComponent))
        .componentInstance as SearchFieldComponent;
      field.search.emit('angular');
      expect(fired).toEqual(['angular']);
    });

    it('forwards searchLoading to the mobile bar field', () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;
      fixture.componentRef.setInput('searchLoading', true);

      (element.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      fixture.detectChanges();

      const field = fixture.debugElement.query(By.directive(SearchFieldComponent))
        .componentInstance as SearchFieldComponent;
      expect(field.loading()).toBe(true);
    });

    it('restores the brand and account when the close control is clicked', () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;

      (element.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      (element.querySelector('[aria-label="Close search"]') as HTMLButtonElement).click();
      fixture.detectChanges();

      expect(fixture.componentInstance.searchOpen()).toBe(false);
      expect(element.querySelector('.brand')).not.toBeNull();
      expect(element.querySelector('[aria-haspopup="menu"]')).not.toBeNull();
    });

    it("carries a single ✕: the field's own, with none beside it (#550)", () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;

      (element.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      fixture.detectChanges();

      // The open bar replaces the whole header, so every button in it belongs
      // to the search. Exactly one, and it is the field's own.
      const buttons = Array.from(element.querySelectorAll('header button'));
      expect(buttons).toHaveLength(1);
      expect(buttons[0].closest('app-search-field')).not.toBeNull();
    });

    it('stays open on a pointerdown outside the bar, so scrolling the results does not dismiss it (#486)', () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;

      (element.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(fixture.componentInstance.searchOpen()).toBe(true);

      // On a phone the results list is "outside" the bar; a scroll touch on it
      // fires pointerdown. The bar must survive that — it closes only on its own
      // ✕ or on the two-step Escape (both covered below).
      document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
      fixture.detectChanges();

      expect(fixture.componentInstance.searchOpen()).toBe(true);
    });

    it('clears without closing on a first Escape over a non-empty field', () => {
      // The two-step contract end to end: the first Escape only clears the
      // text (proven at the field level already), and here specifically it
      // must NOT also close the bar at the header level.
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;

      (element.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      fixture.detectChanges();

      const input = element.querySelector('app-search-field input') as HTMLInputElement;
      input.value = 'cats';
      input.dispatchEvent(new Event('input'));
      fixture.detectChanges();

      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      fixture.detectChanges();

      expect(input.value).toBe('');
      expect(fixture.componentInstance.searchOpen()).toBe(true);
    });

    it('closes when the field reports Escape on an already-empty field', () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;

      (element.querySelector('[aria-label="Search"]') as HTMLButtonElement).click();
      fixture.detectChanges();

      const input = element.querySelector('app-search-field input') as HTMLInputElement;
      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      fixture.detectChanges();

      expect(fixture.componentInstance.searchOpen()).toBe(false);
    });

    it('moves focus into the field on open and back to the trigger on close, twice over', () => {
      const fixture = create();
      const element = fixture.nativeElement as HTMLElement;

      function trigger(): HTMLButtonElement {
        return element.querySelector('[aria-label="Search"]') as HTMLButtonElement;
      }
      function fieldInput(): HTMLInputElement {
        return element.querySelector('app-search-field input') as HTMLInputElement;
      }
      function closeButton(): HTMLButtonElement {
        return element.querySelector('[aria-label="Close search"]') as HTMLButtonElement;
      }

      trigger().click();
      fixture.detectChanges();
      expect(document.activeElement).toBe(fieldInput());

      closeButton().click();
      fixture.detectChanges();
      expect(document.activeElement).toBe(trigger());

      trigger().click();
      fixture.detectChanges();
      expect(document.activeElement).toBe(fieldInput());

      closeButton().click();
      fixture.detectChanges();
      expect(document.activeElement).toBe(trigger());
    });
  });
});
