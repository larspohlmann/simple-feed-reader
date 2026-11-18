import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { EntryDto } from '../../models';
import { StoryArticleDto } from '../trending.models';
import { StorySharersComponent } from './story-sharers.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { anEntry, aStoryArticle } from '../../../../testing/entry-testing';
import { provideAccountIdentity } from '../../../../testing/account-identity-testing';

const open = jest.fn();
let fixture: ComponentFixture<StorySharersComponent>;

beforeEach(() => open.mockReset());

function mount(article: StoryArticleDto): HTMLElement {
  TestBed.configureTestingModule({
    imports: [StorySharersComponent, provideTranslocoTesting()],
    providers: [
      provideAccountIdentity(),
      { provide: EntryActionHandler, useValue: { open } },
      provideRouter([]),
    ],
  });
  fixture = TestBed.createComponent(StorySharersComponent);
  fixture.componentRef.setInput('article', article);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

const share = (id: number, name: string, imageUrl: string | null = null): EntryDto =>
  anEntry({ id, titleDerived: true, poster: { name, url: null, imageUrl } });

const sharedBy = (...shares: EntryDto[]): StoryArticleDto => aStoryArticle({ shares });

const names = (element: HTMLElement): string[] =>
  Array.from(element.querySelectorAll('.sharer .name')).map((name) => name.textContent!.trim());

it('renders nothing for an article no post shares', () => {
  expect(mount(aStoryArticle()).querySelector('.sharers')).toBeNull();
});

it('shows one pill per sharing account, named', () => {
  expect(names(mount(sharedBy(share(2, 'Ann'), share(3, 'Ben'))))).toEqual(['Ann', 'Ben']);
});

it('shows an account that shared the article twice on one pill, opening its newest post', () => {
  const newest = share(2, 'Ann');
  const element = mount(sharedBy(newest, share(3, 'Ben'), share(4, 'Ann')));

  expect(names(element)).toEqual(['Ann', 'Ben']);
  element.querySelector<HTMLButtonElement>('.sharer')!.click();
  expect(open).toHaveBeenCalledWith(newest);
});

const onNetwork = (entry: EntryDto, network: 'bluesky' | 'mastodon'): EntryDto => ({
  ...entry,
  social: { network, isLiked: false, canLike: true, isOwn: false },
});

it("marks each pill with the sharer's network, then its avatar", () => {
  const element = mount(sharedBy(onNetwork(share(2, 'Ann', 'https://img/ann.jpg'), 'mastodon')));

  const pill = element.querySelector('.sharer')!;
  expect(pill.querySelector('app-network-logo svg.mastodon')).not.toBeNull();
  expect(pill.firstElementChild!.tagName).toBe('APP-NETWORK-LOGO');
  expect(pill.querySelector('app-favicon')).not.toBeNull();
});

it('shows no network mark when the network is unknown', () => {
  expect(mount(sharedBy(share(2, 'Ann'))).querySelector('.sharer app-network-logo')).toBeNull();
});

it('keeps one name on two networks as two sharers', () => {
  const element = mount(
    sharedBy(onNetwork(share(2, 'NPR'), 'bluesky'), onNetwork(share(3, 'NPR'), 'mastodon')),
  );

  expect(names(element)).toEqual(['NPR', 'NPR']);
});

const profiled = (id: number, name: string, url: string): EntryDto =>
  anEntry({ id, titleDerived: true, poster: { name, url, imageUrl: null } });

it('keeps two accounts of one name apart by their profiles', () => {
  const element = mount(
    sharedBy(
      profiled(2, 'Ann', 'https://m.example/@ann'),
      profiled(3, 'Ann', 'https://m.example/@ann2'),
    ),
  );

  expect(names(element)).toEqual(['Ann', 'Ann']);
});

it('shows one profile under two names on one pill, its newest', () => {
  const element = mount(
    sharedBy(
      profiled(2, 'Ann B.', 'https://m.example/@ann'),
      profiled(3, 'Ann', 'https://m.example/@ann'),
    ),
  );

  expect(names(element)).toEqual(['Ann B.']);
});

it('keeps two feeds of one name apart', () => {
  const element = mount(
    sharedBy(
      anEntry({ id: 2, source: 'NPR', subscriptionId: 20 }),
      anEntry({ id: 3, source: 'NPR', subscriptionId: 21 }),
    ),
  );

  expect(names(element)).toEqual(['NPR', 'NPR']);
});

it("puts the sharer's avatar on its pill", () => {
  const element = mount(sharedBy(share(2, 'Ann', 'https://img/ann.jpg')));

  expect(element.querySelector('.sharer app-favicon img')!.getAttribute('src')).toContain(
    'ann.jpg',
  );
});

it('opens the post a pill stands for, and not the card around it', () => {
  const ben = share(3, 'Ben');
  const element = mount(sharedBy(share(2, 'Ann'), ben));
  const card = jest.fn();
  element.addEventListener('click', card);

  element.querySelectorAll<HTMLButtonElement>('.sharer')[1].click();

  expect(open).toHaveBeenCalledWith(ben);
  expect(card).not.toHaveBeenCalled();
});

const many = sharedBy(
  share(2, 'Ann'),
  share(3, 'Ben'),
  share(4, 'Cat'),
  share(5, 'Dan'),
  share(6, 'Eve'),
);

it('shows the first sharers and counts the rest on a "+N" pill', () => {
  const element = mount(many);

  expect(names(element)).toEqual(['Ann', 'Ben', 'Cat']);
  expect(element.querySelector('.sharers-more')!.textContent).toContain('+2 more');
});

it('reveals the rest on the "+N" pill, and not the card around it', () => {
  const element = mount(many);
  const card = jest.fn();
  element.addEventListener('click', card);

  element.querySelector<HTMLButtonElement>('.sharers-more')!.click();
  fixture.detectChanges();

  expect(names(element)).toEqual(['Ann', 'Ben', 'Cat', 'Dan', 'Eve']);
  expect(element.querySelector('.sharers-more')).toBeNull();
  expect(card).not.toHaveBeenCalled();
});

it('shows no "+N" pill when every sharer fits', () => {
  expect(mount(sharedBy(share(2, 'Ann'))).querySelector('.sharers-more')).toBeNull();
});

it.each(['.sharer', '.sharers-more'])('keeps a key on "%s" from reaching the card', (selector) => {
  const element = mount(many);
  const card = jest.fn();
  element.addEventListener('keydown', card);

  for (const key of ['Enter', ' ']) {
    element
      .querySelector(selector)!
      .dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));
  }

  expect(card).not.toHaveBeenCalled();
});
