import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { AuthService } from '../../core/auth.service';
import { ReaderModeService } from '../reader-mode.service';
import { ReaderHeaderComponent } from './reader-header.component';
import { signal } from '@angular/core';

describe('ReaderHeaderComponent', () => {
  const auth = { user: signal({ email: 'a@b.c' }), logout: jest.fn(), isAdmin: () => false };
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [ReaderHeaderComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        provideRouter([]),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: AuthService, useValue: auth },
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

  it('keeps the brand and hosts no back button in article mode (back lives in the content)', () => {
    const f = create();
    f.componentRef.setInput('articleOpen', true);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    expect(el.querySelector('.brand')).not.toBeNull();
    expect(el.querySelector('[aria-label="Back to list"]')).toBeNull();
  });

  it('in article mode emits prev/next and reflects disabled ends', () => {
    const f = create();
    f.componentRef.setInput('articleOpen', true);
    f.componentRef.setInput('hasPrev', false);
    f.componentRef.setInput('hasNext', true);
    f.detectChanges();
    const el = f.nativeElement as HTMLElement;
    expect((el.querySelector('[aria-label="Previous"]') as HTMLButtonElement).disabled).toBe(true);
    const next = jest.fn();
    f.componentInstance.next.subscribe(next);
    (el.querySelector('[aria-label="Next"]') as HTMLButtonElement).click();
    expect(next).toHaveBeenCalledTimes(1);
  });

  it('shows the reader/original switch only once toggling is available', () => {
    const f = create();
    f.componentRef.setInput('articleOpen', true);
    f.detectChanges();
    expect(f.nativeElement.querySelector('.mode')).toBeNull();

    const rm = TestBed.inject(ReaderModeService);
    rm.enableToggle();
    f.detectChanges();
    const mode = f.nativeElement.querySelector('.mode') as HTMLButtonElement;
    expect(mode).not.toBeNull();
    expect(mode.getAttribute('aria-pressed')).toBe('true');
    mode.click();
    expect(rm.mode()).toBe('original');
  });

  it('shows a Settings link, and Admin only for admins', () => {
    const f = create();
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('[aria-haspopup="menu"]') as HTMLButtonElement).click();
    f.detectChanges();
    expect(el.querySelector('a[routerLink="/settings"]')).not.toBeNull();
    expect(el.querySelector('a[routerLink="/admin/users"]')).toBeNull();
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
});
