// src/app/shell/shell.component.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { Router } from '@angular/router';
import { API_BASE_URL } from '../core/api';
import { AuthService } from '../core/auth.service';
import { ThemeService } from '../theme/theme.service';
import { ShellComponent } from './shell.component';

describe('ShellComponent', () => {
  let ctrl: HttpTestingController;
  beforeEach(async () => {
    localStorage.clear();
    window.matchMedia = jest
      .fn()
      .mockReturnValue({ matches: false, addEventListener: jest.fn() }) as never;
    await TestBed.configureTestingModule({
      imports: [ShellComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: Router, useValue: { navigate: jest.fn() } },
      ],
    }).compileComponents();
    ctrl = TestBed.inject(HttpTestingController);
  });

  it('loads the current user on init and shows the email', () => {
    const f = TestBed.createComponent(ShellComponent);
    f.detectChanges();
    ctrl
      .expectOne('https://api.test/api/me')
      .flush({ id: 1, email: 'me@ex.com', roles: [], status: 'active', createdAt: 'x' });
    f.detectChanges();
    expect((f.nativeElement as HTMLElement).textContent).toContain('me@ex.com');
  });

  it('setMode on the theme service changes the applied theme', () => {
    const f = TestBed.createComponent(ShellComponent);
    f.detectChanges();
    ctrl
      .expectOne('https://api.test/api/me')
      .flush({ id: 1, email: 'me@ex.com', roles: [], status: 'active', createdAt: 'x' });
    TestBed.inject(ThemeService).setMode('dark');
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    // sanity: AuthService is wired
    expect(TestBed.inject(AuthService).user()?.email).toBe('me@ex.com');
  });
});
