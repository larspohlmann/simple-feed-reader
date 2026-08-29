import { Component } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { Title } from '@angular/platform-browser';
import { Router, TitleStrategy, provideRouter } from '@angular/router';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { PageTitleService } from './page-title.service';
import { DYNAMIC_TITLE, TranslatedTitleStrategy } from './translated-title.strategy';

@Component({ selector: 'app-blank', template: '' })
class BlankComponent {}

describe('TranslatedTitleStrategy', () => {
  let router: Router;
  let title: Title;

  beforeEach(() => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideRouter([
          { path: 'account', title: 'settings.account.title', component: BlankComponent },
          { path: 'organise', title: 'settings.organise.title', component: BlankComponent },
          { path: 'untitled', component: BlankComponent },
          { path: 'reader', title: DYNAMIC_TITLE, component: BlankComponent },
        ]),
        { provide: TitleStrategy, useExisting: TranslatedTitleStrategy },
      ],
    });
    router = TestBed.inject(Router);
    title = TestBed.inject(Title);
  });

  async function navigate(url: string): Promise<void> {
    await router.navigateByUrl(url);
    TestBed.tick();
  }

  it('titles each page from the translation key on its route, on every navigation', async () => {
    await navigate('/account');
    await navigate('/organise');

    expect(title.getTitle()).toBe('Organise | simple feed reader');
  });

  it('resets a route that declares no title instead of leaving the last one', async () => {
    await navigate('/account');
    await navigate('/untitled');

    expect(title.getTitle()).toBe('simple feed reader');
  });

  it('leaves a page that names itself to do it', async () => {
    TestBed.inject(PageTitleService).useText('The Verge');
    TestBed.tick();

    await navigate('/reader');

    expect(title.getTitle()).toBe('The Verge | simple feed reader');
  });
});
