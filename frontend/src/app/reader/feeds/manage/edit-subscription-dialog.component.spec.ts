import { inject } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { API_BASE_URL } from '../../../core/api';
import { EditSubscriptionDialogComponent } from './edit-subscription-dialog.component';
import { SubscriptionDto } from '../../models';
import { SubscriptionRows } from '../../state/subscription-rows';

const sub: SubscriptionDto = {
  id: 5,
  feedId: 50,
  title: 'Heise',
  faviconUrl: null,
  customTitle: null,
  feedUrl: 'https://heise.de/rss',
  siteUrl: 'https://heise.de',
  description: null,
  imageUrl: null,
  status: 'active',
  sourceFormat: 'xml',
  createdAt: 'x',
  lastFetchedAt: null,
  lastSuccessfulFetchAt: null,
  lastNewContentAt: null,
  nextFetchAt: null,
  consecutiveFailures: 0,
  lastErrorMessage: null,
  position: 0,
  tags: [{ id: 1, name: 'Tech', color: null, icon: null, position: 0, parentId: null }],
  unreadCount: 3,
  entryCount: 0,
  includeInAllItems: true,
  includeInForYou: true,
  includeInSavedSearches: true,
  social: null,
};

describe('EditSubscriptionDialogComponent', () => {
  const close = jest.fn();
  let ctrl: HttpTestingController;

  function mount() {
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: sub },
      ],
    });
    const fixture = TestBed.createComponent(EditSubscriptionDialogComponent);
    fixture.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    ctrl.expectOne('https://api.test/api/tags').flush({
      tags: [
        { id: 1, name: 'Tech', color: null, icon: null },
        { id: 2, name: 'News', color: 'rgb(34, 197, 94)', icon: 'public' },
      ],
    });
    fixture.detectChanges(); // render the tag pills now the store has loaded
    return fixture;
  }

  beforeEach(() => close.mockReset());
  afterEach(() => ctrl.verify());

  it('prefills the name with the title as it reads now', () => {
    expect(mount().componentInstance.form.getRawValue().customTitle).toBe('Heise');
  });

  it('clears the field on reset, which is what drops the override', () => {
    const component = mount().componentInstance;
    component.resetTitle();
    expect(component.form.getRawValue().customTitle).toBe('');
  });

  it('shows the feed URL, and the site as an external link', () => {
    const element: HTMLElement = mount().nativeElement;
    expect(element.querySelector('.url')!.textContent).toContain('https://heise.de/rss');
    const link = element.querySelector<HTMLAnchorElement>('.site a')!;
    expect(link.getAttribute('href')).toBe('https://heise.de');
    expect(link.target).toBe('_blank');
    expect(link.rel).toBe('noopener noreferrer');
  });

  it('prefills the current tags as checked', () => {
    const component = mount().componentInstance;
    expect(component.checked().has(1)).toBe(true);
    expect(component.checked().has(2)).toBe(false);
  });

  const chipLabels = (element: HTMLElement): string[] =>
    [...element.querySelectorAll('.chip .label')].map((label) => label.textContent!.trim());

  function openList(fixture: ComponentFixture<EditSubscriptionDialogComponent>): HTMLElement[] {
    const query = (fixture.nativeElement as HTMLElement).querySelector('input.query')!;
    query.dispatchEvent(new Event('focus'));
    fixture.detectChanges();
    return [
      ...(fixture.nativeElement as HTMLElement).querySelectorAll<HTMLElement>('[role="option"]'),
    ];
  }

  it('shows the current tags as chips', () => {
    expect(chipLabels(mount().nativeElement)).toEqual(['Tech']);
  });

  it("shows a tag's icon when it has one, and a colour dot otherwise", () => {
    const options = openList(mount());
    const tech = options.find((option) => option.textContent!.includes('Tech'))!;
    const news = options.find((option) => option.textContent!.includes('News'))!;
    expect(news.querySelector('app-tag-glyph app-icon')).not.toBeNull();
    expect(news.querySelector('.dot')).toBeNull();
    expect(tech.querySelector('app-tag-glyph app-icon')).toBeNull();
    expect(tech.querySelector('.dot')).not.toBeNull();
  });

  it('colours a tag icon with the tag colour', () => {
    const news = openList(mount()).find((option) => option.textContent!.includes('News'))!;
    const icon = news.querySelector('app-tag-glyph app-icon') as HTMLElement;
    expect(icon.style.color).toBe('rgb(34, 197, 94)');
  });

  it('toggles a tag when its option is clicked', () => {
    const fixture = mount();
    openList(fixture)
      .find((option) => option.textContent!.includes('News'))!
      .click();
    fixture.detectChanges();
    expect(fixture.componentInstance.checked().has(2)).toBe(true);
    expect(chipLabels(fixture.nativeElement).sort()).toEqual(['News', 'Tech']);
  });

  it('suggests tags from other subscriptions, never from this one', () => {
    const fixture = mount();
    TestBed.inject(SubscriptionRows).stored.set([
      { ...sub, createdAt: '2026-05-01T00:00:00Z' },
      {
        ...sub,
        id: 6,
        createdAt: '2026-01-01T00:00:00Z',
        tags: [{ id: 2, name: 'News', color: null, icon: null, position: 1, parentId: null }],
      },
    ]);
    fixture.componentInstance.toggle(1);
    fixture.detectChanges();

    const suggestions = [
      ...(fixture.nativeElement as HTMLElement).querySelectorAll('.suggestion .label'),
    ].map((suggestion) => suggestion.textContent!.trim());
    expect(suggestions).toEqual(['News']);
  });

  it('PATCHes customTitle (empty → null) and the toggled tag set', () => {
    const component = mount().componentInstance;
    component.form.controls.customTitle.setValue('  My Heise ');
    component.toggle(2); // add News
    component.toggle(1); // remove Tech
    component.submit();
    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(testRequest.request.method).toBe('PATCH');
    expect(testRequest.request.body).toEqual({
      customTitle: 'My Heise',
      tagIds: [2],
      includeInAllItems: true,
      includeInForYou: true,
      includeInSavedSearches: true,
    });
    testRequest.flush({ subscription: { ...sub, customTitle: 'My Heise' } });
    expect(close).toHaveBeenCalled();
  });

  it('sends customTitle null when cleared', () => {
    const component = mount().componentInstance;
    component.form.controls.customTitle.setValue('');
    component.submit();
    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(testRequest.request.body).toEqual({
      customTitle: null,
      tagIds: [1],
      includeInAllItems: true,
      includeInForYou: true,
      includeInSavedSearches: true,
    });
    testRequest.flush({ subscription: sub });
  });

  it('reflects stored exclusion state and sends the toggled values', () => {
    const excludedFromAllItems: SubscriptionDto = {
      ...sub,
      includeInAllItems: false,
      includeInSavedSearches: false,
    };
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: excludedFromAllItems },
      ],
    });
    const fixture = TestBed.createComponent(EditSubscriptionDialogComponent);
    fixture.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });
    fixture.detectChanges();

    const element = fixture.nativeElement as HTMLElement;
    const allItemsSwitch = element.querySelector<HTMLInputElement>('#edit-feed-all-items');
    const forYouSwitch = element.querySelector<HTMLInputElement>('#edit-feed-for-you');
    const savedSearchesSwitch = element.querySelector<HTMLInputElement>(
      '#edit-feed-saved-searches',
    );
    expect(allItemsSwitch!.checked).toBe(false);
    expect(forYouSwitch!.checked).toBe(true);
    expect(savedSearchesSwitch!.checked).toBe(false);

    forYouSwitch!.click();
    savedSearchesSwitch!.click();
    fixture.detectChanges();
    fixture.componentInstance.submit();

    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(testRequest.request.body).toEqual({
      customTitle: 'Heise',
      tagIds: [1],
      includeInAllItems: false,
      includeInForYou: false,
      includeInSavedSearches: true,
    });
    testRequest.flush({ subscription: excludedFromAllItems });
  });

  it('prefills an unrenamed Home with its label and sends no custom title when left as is', () => {
    const home: SubscriptionDto = {
      ...sub,
      title: 'Timeline',
      customTitle: null,
      social: { network: 'bluesky', accountId: 3, kind: 'home' },
    };
    TestBed.configureTestingModule({
      imports: [provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
        {
          provide: DIALOG_DATA,
          useFactory: () => {
            const rows = inject(SubscriptionRows);
            rows.stored.set([home]);
            return rows.find(home.id);
          },
        },
      ],
    });
    const fixture = TestBed.createComponent(EditSubscriptionDialogComponent);
    fixture.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    ctrl.expectOne('https://api.test/api/tags').flush({ tags: [] });

    expect(fixture.componentInstance.form.controls.customTitle.value).toBe('Home@bsky');
    fixture.componentInstance.submit();

    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(testRequest.request.body.customTitle).toBeNull();
    testRequest.flush({ subscription: home });
  });
});
