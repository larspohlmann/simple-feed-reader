import { TestBed } from '@angular/core/testing';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DialogRef, DIALOG_DATA } from '@angular/cdk/dialog';
import { API_BASE_URL } from '../../core/api';
import { EditSubscriptionDialogComponent } from './edit-subscription-dialog.component';
import { SubscriptionDto } from '../models';

const sub: SubscriptionDto = {
  id: 5,
  title: 'Heise',
  customTitle: null,
  feedUrl: 'https://heise.de/rss',
  siteUrl: 'https://heise.de',
  status: 'active',
  sourceFormat: 'xml',
  createdAt: 'x',
  position: 0,
  tags: [{ id: 1, name: 'Tech', color: null, icon: null, position: 0 }],
  unreadCount: 3,
};

describe('EditSubscriptionDialogComponent', () => {
  const close = jest.fn();
  let ctrl: HttpTestingController;

  function mount() {
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: DialogRef, useValue: { close } },
        { provide: DIALOG_DATA, useValue: sub },
      ],
    });
    const f = TestBed.createComponent(EditSubscriptionDialogComponent);
    f.detectChanges();
    ctrl = TestBed.inject(HttpTestingController);
    // ngOnInit loads all tags:
    ctrl.expectOne('https://api.test/api/tags').flush({
      tags: [
        { id: 1, name: 'Tech', color: null, icon: null },
        { id: 2, name: 'News', color: 'rgb(34, 197, 94)', icon: 'public' },
      ],
    });
    f.detectChanges(); // render the tag pills now the store has loaded
    return f;
  }

  beforeEach(() => close.mockReset());
  afterEach(() => ctrl.verify());

  it('prefills the current tags as checked', () => {
    const c = mount().componentInstance;
    expect(c.checked().has(1)).toBe(true);
    expect(c.checked().has(2)).toBe(false);
  });

  it('renders each tag as a toggle pill with aria-pressed reflecting selection', () => {
    const el = mount().nativeElement as HTMLElement;
    const pills = el.querySelectorAll('button.tag-pill');
    expect(pills.length).toBe(2);
    // Tech (id 1) is a current tag → pressed; News (id 2) → not pressed.
    const tech = [...pills].find((p) => p.textContent!.includes('Tech'))!;
    const news = [...pills].find((p) => p.textContent!.includes('News'))!;
    expect(tech.getAttribute('aria-pressed')).toBe('true');
    expect(news.getAttribute('aria-pressed')).toBe('false');
  });

  it("shows a tag's icon when it has one, and a colour dot otherwise", () => {
    const el = mount().nativeElement as HTMLElement;
    const pills = [...el.querySelectorAll('button.tag-pill')];
    const tech = pills.find((p) => p.textContent!.includes('Tech'))!;
    const news = pills.find((p) => p.textContent!.includes('News'))!;
    // News carries an icon → renders app-icon, no dot; Tech has none → dot.
    expect(news.querySelector('app-icon')).not.toBeNull();
    expect(news.querySelector('.dot')).toBeNull();
    expect(tech.querySelector('app-icon')).toBeNull();
    expect(tech.querySelector('.dot')).not.toBeNull();
  });

  it('colours an inactive tag icon with the tag colour', () => {
    const el = mount().nativeElement as HTMLElement;
    // News (id 2) is not one of the feed's tags → inactive → shows its own colour.
    const news = [...el.querySelectorAll('button.tag-pill')].find((p) =>
      p.textContent!.includes('News'),
    )!;
    const icon = news.querySelector('app-icon') as HTMLElement;
    expect(icon.style.color).toBe('rgb(34, 197, 94)');
  });

  it('toggles a tag when its pill is clicked', () => {
    const f = mount();
    const el = f.nativeElement as HTMLElement;
    const news = [...el.querySelectorAll('button.tag-pill')].find((p) =>
      p.textContent!.includes('News'),
    ) as HTMLButtonElement;
    news.click();
    f.detectChanges();
    expect(f.componentInstance.checked().has(2)).toBe(true);
    expect(news.getAttribute('aria-pressed')).toBe('true');
  });

  it('PATCHes customTitle (empty → null) and the toggled tag set', () => {
    const c = mount().componentInstance;
    c.form.controls.customTitle.setValue('  My Heise ');
    c.toggle(2); // add News
    c.toggle(1); // remove Tech
    c.submit();
    const req = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(req.request.method).toBe('PATCH');
    expect(req.request.body).toEqual({ customTitle: 'My Heise', tagIds: [2] });
    req.flush({ subscription: { ...sub, customTitle: 'My Heise' } });
    expect(close).toHaveBeenCalled();
  });

  it('sends customTitle null when cleared', () => {
    const c = mount().componentInstance;
    c.form.controls.customTitle.setValue('');
    c.submit();
    const req = ctrl.expectOne('https://api.test/api/subscriptions/5');
    expect(req.request.body).toEqual({ customTitle: null, tagIds: [1] });
    req.flush({ subscription: sub });
  });
});
