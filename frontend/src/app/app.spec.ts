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
      // provideTranslocoTesting() belongs in `imports` (a ModuleWithProviders),
      // as every other component spec here does. It loads the real shipped
      // dictionaries, so assertions below check actual English UI strings.
      imports: [App, provideTranslocoTesting()],
      // provideRouter supplies the context <router-outlet> needs to render.
      providers: [
        provideRouter([]),
        {
          provide: NavigationFailureReporter,
          useValue: { failed } satisfies Partial<NavigationFailureReporter>,
        },
      ],
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

    // Load-bearing: index.html's boot watchdog (#282) cancels its timer once
    // <app-root> holds any non-<router-outlet> element. The banner must stay
    // behind @if (a comment anchor, not an element) or the blank page returns.
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
