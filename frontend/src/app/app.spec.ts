// src/app/app.spec.ts
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { signal } from '@angular/core';
import { provideTranslocoTesting } from '../testing/transloco-testing';
import { App } from './app';
import { NavigationFailureReporter } from './core/navigation-failure';

describe('App', () => {
  const failed = signal(false);

  async function render() {
    await TestBed.configureTestingModule({
      // provideTranslocoTesting() returns a ModuleWithProviders and belongs in
      // `imports`, as every other component spec in this repo does it. It loads
      // the real shipped dictionaries, so the assertions below are against the
      // actual English UI strings.
      imports: [App, provideTranslocoTesting()],
      // provideRouter supplies the context <router-outlet> needs to render.
      providers: [provideRouter([]), { provide: NavigationFailureReporter, useValue: { failed } }],
    }).compileComponents();
    const fixture = TestBed.createComponent(App);
    fixture.detectChanges();
    return fixture;
  }

  beforeEach(() => {
    failed.set(false);
    TestBed.resetTestingModule();
  });

  it('creates the root component', async () => {
    const fixture = await render();
    expect(fixture.componentInstance).toBeTruthy();
  });

  it('renders no element besides the outlet while navigation is healthy', async () => {
    const fixture = await render();

    // Load-bearing, not cosmetic: index.html's boot watchdog (#282) cancels
    // its 15 s timer as soon as <app-root> holds any element that is not
    // <router-outlet>. Static chrome here would disarm it ~70 ms into
    // bootstrap and bring back the blank page of #282, so the banner must
    // stay behind an @if — which renders a comment anchor, not an element.
    const host: HTMLElement = fixture.nativeElement;
    expect(host.querySelector(':not(router-outlet)')).toBeNull();
  });

  it('renders the retry banner when navigation has failed', async () => {
    const fixture = await render();

    failed.set(true);
    fixture.detectChanges();

    const banner: HTMLElement | null = fixture.nativeElement.querySelector('.banner');
    expect(banner?.textContent).toContain('That page did not load.');
    expect(banner?.querySelector('button')?.textContent).toContain('Retry');
  });
});
