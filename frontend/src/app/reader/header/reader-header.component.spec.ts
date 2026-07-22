import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { API_BASE_URL } from '../../core/api';
import { RefreshService } from '../refresh.service';
import { ReadingLayoutService } from '../reading-layout.service';
import { AuthService } from '../../core/auth.service';
import { ReaderHeaderComponent } from './reader-header.component';
import { signal } from '@angular/core';

describe('ReaderHeaderComponent', () => {
  const auth = { user: signal({ email: 'a@b.c' }), logout: jest.fn() };
  beforeEach(() => {
    localStorage.clear();
    TestBed.configureTestingModule({
      imports: [ReaderHeaderComponent],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
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

  it('shows the busy state while refreshing', () => {
    const f = create();
    TestBed.inject(RefreshService).running.set(true);
    f.detectChanges();
    expect(
      (f.nativeElement.querySelector('[aria-label="Refresh"]') as HTMLButtonElement).disabled,
    ).toBe(true);
  });
});
