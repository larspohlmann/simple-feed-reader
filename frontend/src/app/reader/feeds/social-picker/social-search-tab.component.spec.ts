import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Observable, of } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { ImageProxyService } from '../../../shared/proxied-image/image-proxy.service';
import { SocialImportService } from './social-import.service';
import { SearchPreview, SocialSearchService } from './social-search.service';
import { SocialSearchTabComponent } from './social-search-tab.component';

describe('SocialSearchTabComponent', () => {
  const socialSearch = { preview: jest.fn<Observable<SearchPreview>, [number, string]>() };

  function mount(): ComponentFixture<SocialSearchTabComponent> {
    TestBed.configureTestingModule({
      imports: [SocialSearchTabComponent, provideTranslocoTesting()],
      providers: [
        { provide: SocialImportService, useValue: { importing: () => false } },
        { provide: SocialSearchService, useValue: socialSearch },
        { provide: ImageProxyService, useValue: { recover: () => Promise.resolve('failed') } },
      ],
    });
    const fixture = TestBed.createComponent(SocialSearchTabComponent);
    fixture.componentRef.setInput('network', 'bluesky');
    fixture.componentRef.setInput('accountId', 4);
    fixture.detectChanges();
    return fixture;
  }

  const element = (fixture: ComponentFixture<SocialSearchTabComponent>): HTMLElement =>
    fixture.nativeElement as HTMLElement;

  const buttonOf = (
    fixture: ComponentFixture<SocialSearchTabComponent>,
    testId: string,
  ): HTMLButtonElement =>
    element(fixture).querySelector(`[data-testid="${testId}"] button`) as HTMLButtonElement;

  function type(fixture: ComponentFixture<SocialSearchTabComponent>, text: string): void {
    const field = element(fixture).querySelector(
      '[data-testid="social-search-query"]',
    ) as HTMLTextAreaElement;
    field.value = text;
    field.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  }

  function press(fixture: ComponentFixture<SocialSearchTabComponent>, testId: string): void {
    buttonOf(fixture, testId).click();
    fixture.detectChanges();
  }

  beforeEach(() => socialSearch.preview.mockReset().mockReturnValue(of({ count: 0, posts: [] })));

  it('explains that a subscribed search becomes a feed', () => {
    const fixture = mount();
    const intro = element(fixture).querySelector('[data-testid="social-search-intro"]');
    expect(intro?.textContent?.trim()).toContain('Turn a search into a feed');
  });

  it('subscribes only to the query it has previewed, even when nothing matches yet', () => {
    const fixture = mount();
    const emitted: string[] = [];
    fixture.componentInstance.subscribe.subscribe((query) => emitted.push(query));
    type(fixture, ' rss reader ');
    expect(buttonOf(fixture, 'social-search-subscribe').disabled).toBe(true);

    press(fixture, 'social-search-preview');
    expect(buttonOf(fixture, 'social-search-subscribe').disabled).toBe(false);

    type(fixture, 'rss readers');
    expect(buttonOf(fixture, 'social-search-subscribe').disabled).toBe(true);

    type(fixture, 'rss reader');
    press(fixture, 'social-search-subscribe');
    expect(emitted).toEqual(['rss reader']);
  });

  it('subscribes to a query typed over several lines as one line', () => {
    const fixture = mount();
    const emitted: string[] = [];
    fixture.componentInstance.subscribe.subscribe((query) => emitted.push(query));
    type(fixture, 'rss\nreader');
    press(fixture, 'social-search-preview');

    press(fixture, 'social-search-subscribe');

    expect(socialSearch.preview).toHaveBeenCalledWith(4, 'rss reader');
    expect(emitted).toEqual(['rss reader']);
  });

  it('puts the caret in the query field', async () => {
    const fixture = mount();
    await fixture.whenStable();

    expect(document.activeElement).toBe(
      element(fixture).querySelector('[data-testid="social-search-query"]'),
    );
  });
});
