import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { TranslocoService } from '@jsverse/transloco';
import { EntryDto } from '../../models';
import { StorySharesComponent } from './story-shares.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { anEntry } from '../../../../testing/entry-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';

const open = jest.fn();
let fixture: ComponentFixture<StorySharesComponent>;

beforeEach(() => open.mockReset());

function mount(entry: EntryDto, lang = 'en'): HTMLElement {
  TestBed.configureTestingModule({
    imports: [StorySharesComponent, provideTranslocoTesting()],
    providers: [
      provideAccountIdentity(),
      { provide: EntryActionHandler, useValue: { open } },
      provideRouter([]),
    ],
  });
  fixture = TestBed.createComponent(StorySharesComponent);
  fixture.componentRef.setInput('entry', entry);
  TestBed.inject(TranslocoService).setActiveLang(lang);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

const share = (id: number, name: string, excerpt: string): EntryDto =>
  anEntry({ id, excerpt, titleDerived: true, poster: { name, url: null, imageUrl: null } });

const sharedBy = (...shares: EntryDto[]): EntryDto => anEntry({ shares });

it('renders nothing for an article no post shares', () => {
  expect(mount(anEntry({ shares: [] })).querySelector('.shares')).toBeNull();
});

it('renders nothing for a row from a server without shares', () => {
  expect(mount(anEntry()).querySelector('.shares')).toBeNull();
});

it('names one sharer', () => {
  expect(mount(sharedBy(share(2, 'Ann', 'Look'))).textContent).toContain('Shared by Ann');
});

it('names two sharers as a list in the reader’s language', () => {
  const element = mount(sharedBy(share(2, 'Ann', 'Look'), share(3, 'Ben', 'Read')));

  expect(element.textContent).toContain('Shared by Ann and Ben');
});

it('names the newest two sharers and counts the rest', () => {
  const element = mount(
    sharedBy(
      share(2, 'Ann', 'a'),
      share(3, 'Ben', 'b'),
      share(4, 'Cat', 'c'),
      share(5, 'Dan', 'd'),
    ),
  );

  expect(element.textContent).toContain('Shared by Ann, Ben and 2 others');
});

it('says "and 1 other" for a third sharer', () => {
  const element = mount(sharedBy(share(2, 'Ann', 'a'), share(3, 'Ben', 'b'), share(4, 'Cat', 'c')));

  expect(element.textContent).toContain('Shared by Ann, Ben and 1 other');
});

it('reads in German', () => {
  const element = mount(
    sharedBy(share(2, 'Ann', 'a'), share(3, 'Ben', 'b'), share(4, 'Cat', 'c')),
    'de',
  );

  expect(element.textContent).toContain('Geteilt von Ann, Ben und einer weiteren Person');
});

it('expands into each sharing post, poster and text, and opens the one picked', () => {
  const ben = share(3, 'Ben', 'Worth reading');
  const element = mount(sharedBy(share(2, 'Ann', 'Look at this'), ben));
  const toggle = element.querySelector<HTMLButtonElement>('.toggle')!;
  expect(toggle.getAttribute('aria-expanded')).toBe('false');
  expect(element.querySelector('.share')).toBeNull();

  toggle.click();
  fixture.detectChanges();

  const posts = element.querySelectorAll<HTMLButtonElement>('.share');
  expect(posts).toHaveLength(2);
  expect(posts[1].querySelector('.poster')!.textContent).toContain('Ben');
  expect(posts[1].querySelector('.text')!.textContent).toContain('Worth reading');
  posts[1].click();
  expect(open).toHaveBeenCalledWith(ben);
});

it('keeps a click on the line from opening the card around it', () => {
  const element = mount(sharedBy(share(2, 'Ann', 'Look')));
  const card = jest.fn();
  element.addEventListener('click', card);

  element.querySelector<HTMLButtonElement>('.toggle')!.click();

  expect(card).not.toHaveBeenCalled();
});
