import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { API_BASE_URL } from '../../../core/api';
import { EditSubscriptionDialogComponent } from './edit-subscription-dialog.component';
import { SubscriptionDto } from '../../models';

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
  tags: [{ id: 1, name: 'Tech', color: null, icon: null, position: 0 }],
  unreadCount: 3,
  entryCount: 0,
  includeInAllItems: true,
  includeInForYou: true,
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

  it('renders each tag as a toggle pill with aria-pressed reflecting selection', () => {
    const element = mount().nativeElement as HTMLElement;
    const pills = element.querySelectorAll('button.tag-pill');
    expect(pills.length).toBe(2);
    // Tech (id 1) is a current tag → pressed; News (id 2) → not pressed.
    const tech = [...pills].find((pill) => pill.textContent!.includes('Tech'))!;
    const news = [...pills].find((pill) => pill.textContent!.includes('News'))!;
    expect(tech.getAttribute('aria-pressed')).toBe('true');
    expect(news.getAttribute('aria-pressed')).toBe('false');
  });

  it("shows a tag's icon when it has one, and a colour dot otherwise", () => {
    const element = mount().nativeElement as HTMLElement;
    const pills = [...element.querySelectorAll('button.tag-pill')];
    const tech = pills.find((pill) => pill.textContent!.includes('Tech'))!;
    const news = pills.find((pill) => pill.textContent!.includes('News'))!;
    // News carries an icon → renders app-icon, no dot; Tech has none → dot.
    expect(news.querySelector('app-icon')).not.toBeNull();
    expect(news.querySelector('.dot')).toBeNull();
    expect(tech.querySelector('app-icon')).toBeNull();
    expect(tech.querySelector('.dot')).not.toBeNull();
  });

  it('colours an inactive tag icon with the tag colour', () => {
    const element = mount().nativeElement as HTMLElement;
    // News (id 2) is not one of the feed's tags → inactive → shows its own colour.
    const news = [...element.querySelectorAll('button.tag-pill')].find((pill) =>
      pill.textContent!.includes('News'),
    )!;
    const icon = news.querySelector('app-icon') as HTMLElement;
    expect(icon.style.color).toBe('rgb(34, 197, 94)');
  });

  it('toggles a tag when its pill is clicked', () => {
    const fixture = mount();
    const element = fixture.nativeElement as HTMLElement;
    const news = [...element.querySelectorAll('button.tag-pill')].find((pill) =>
      pill.textContent!.includes('News'),
    ) as HTMLButtonElement;
    news.click();
    fixture.detectChanges();
    expect(fixture.componentInstance.checked().has(2)).toBe(true);
    expect(news.getAttribute('aria-pressed')).toBe('true');
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
    });
    testRequest.flush({ subscription: sub });
  });

  it('reflects stored exclusion state and sends the toggled values', () => {
    const excludedFromAllItems: SubscriptionDto = { ...sub, includeInAllItems: false };
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
    expect(allItemsSwitch!.checked).toBe(false);
    expect(forYouSwitch!.checked).toBe(true);

    forYouSwitch!.click();
    fixture.detectChanges();
    fixture.componentInstance.submit();

    const testRequest = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(testRequest.request.body).toEqual({
      customTitle: 'Heise',
      tagIds: [1],
      includeInAllItems: false,
      includeInForYou: false,
    });
    testRequest.flush({ subscription: excludedFromAllItems });
  });
});
