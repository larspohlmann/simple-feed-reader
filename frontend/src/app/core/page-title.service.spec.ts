import { TestBed } from '@angular/core/testing';
import { Title } from '@angular/platform-browser';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { PageTitleService } from './page-title.service';

describe('PageTitleService', () => {
  let service: PageTitleService;
  let title: Title;

  beforeEach(() => {
    TestBed.resetTestingModule();
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
    service = TestBed.inject(PageTitleService);
    title = TestBed.inject(Title);
    TestBed.tick();
  });

  it('shows the product name alone until a page names itself', () => {
    expect(title.getTitle()).toBe('simple feed reader');
  });

  it('puts the translated page name in front of the product name', () => {
    service.useKey('settings.account.title');
    TestBed.tick();

    expect(title.getTitle()).toBe('Account | simple feed reader');
  });

  it('follows a language switch without a further navigation', () => {
    service.useKey('settings.account.title');
    TestBed.tick();

    TestBed.inject(TranslocoService).setActiveLang('de');
    TestBed.tick();

    expect(title.getTitle()).toBe('Konto | simple feed reader');
  });

  it('takes finished text from a page that names itself', () => {
    service.useText('The Verge');
    TestBed.tick();

    expect(title.getTitle()).toBe('The Verge | simple feed reader');
  });

  it('drops back to the product name on reset', () => {
    service.useText('The Verge');
    TestBed.tick();

    service.reset();
    TestBed.tick();

    expect(title.getTitle()).toBe('simple feed reader');
  });

  it('appends the count of what the page holds', () => {
    service.useText('The Verge', 4);
    TestBed.tick();

    expect(title.getTitle()).toBe('The Verge (4) | simple feed reader');
  });

  it('shows no count for a page with nothing in it', () => {
    service.useText('The Verge', 0);
    TestBed.tick();

    expect(title.getTitle()).toBe('The Verge | simple feed reader');
  });

  it('keeps the count after a long name is cut to what a tab shows', () => {
    const name = 'A feed name far longer than any browser tab has ever been able to show';
    service.useText(name, 12);
    TestBed.tick();

    expect(title.getTitle()).toBe(`${name.slice(0, 60)}… (12) | simple feed reader`);
  });

  it('drops the count with the name on reset', () => {
    service.useText('The Verge', 4);
    TestBed.tick();

    service.reset();
    TestBed.tick();

    expect(title.getTitle()).toBe('simple feed reader');
  });

  it('does not repeat the product name for a feed that carries it', () => {
    service.useText('simple feed reader');
    TestBed.tick();

    expect(title.getTitle()).toBe('simple feed reader');
  });
});
