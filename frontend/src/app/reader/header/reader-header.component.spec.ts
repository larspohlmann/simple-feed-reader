import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { provideRouter } from '@angular/router';
import { API_BASE_URL } from '../../core/api';
import { RefreshService } from '../refresh.service';
import { ReadingLayoutService } from '../reading-layout.service';
import { AuthService } from '../../core/auth.service';
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
    f.componentRef.setInput('title', 'All items');
    f.detectChanges();
    return f;
  }

  it('emits refresh and addFeed', () => {
    const f = create();
    let refresh = 0,
      add = 0;
    f.componentInstance.refresh.subscribe(() => refresh++);
    f.componentInstance.addFeed.subscribe(() => add++);
    const el = f.nativeElement as HTMLElement;
    (el.querySelector('[aria-label="Refresh"]') as HTMLButtonElement).click();
    (el.querySelector('[aria-label="Add feed"]') as HTMLButtonElement).click();
    expect([refresh, add]).toEqual([1, 1]);
  });

  it('toggles the reading layout', () => {
    const f = create();
    const layout = TestBed.inject(ReadingLayoutService);
    (f.nativeElement.querySelector('[aria-label="Pane layout"]') as HTMLButtonElement).click();
    expect(layout.mode()).toBe('pane');
  });

  it('shows a Magazine layout button first, and switches to it', () => {
    const f = create();
    const layout = TestBed.inject(ReadingLayoutService);
    const group = f.nativeElement.querySelector('[aria-label="Reading layout"]') as HTMLElement;
    const buttons = group.querySelectorAll('button');
    expect(buttons[0].getAttribute('aria-label')).toBe('Magazine layout');

    layout.set('list');
    f.detectChanges();
    expect(group.querySelector('[aria-label="Magazine layout"]')!.classList).not.toContain(
      'active',
    );

    (group.querySelector('[aria-label="Magazine layout"]') as HTMLButtonElement).click();
    expect(layout.mode()).toBe('magazine');
  });

  it('shows the busy state while refreshing', () => {
    const f = create();
    TestBed.inject(RefreshService).running.set(true);
    f.detectChanges();
    expect(
      (f.nativeElement.querySelector('[aria-label="Refresh"]') as HTMLButtonElement).disabled,
    ).toBe(true);
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
