import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { EntryDto } from '../../models';
import { MoreSourcesComponent } from './more-sources.component';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { EntryActionHandler } from '../../entry/entry-actions/entry-action-handler';
import { anEntry } from '../../../../testing/entry-testing';

const open = jest.fn();
let fixture: ComponentFixture<MoreSourcesComponent>;

beforeEach(() => open.mockReset());

function mount(entry: EntryDto): HTMLElement {
  TestBed.configureTestingModule({
    imports: [MoreSourcesComponent, provideTranslocoTesting()],
    providers: [{ provide: EntryActionHandler, useValue: { open } }, provideRouter([])],
  });
  fixture = TestBed.createComponent(MoreSourcesComponent);
  fixture.componentRef.setInput('entry', entry);
  fixture.detectChanges();
  return fixture.nativeElement as HTMLElement;
}

function expand(element: HTMLElement): void {
  element.querySelector<HTMLButtonElement>('.toggle')!.click();
  fixture.detectChanges();
}

const copies = [
  anEntry({ id: 2, source: 'NDR', title: 'Storm hits the coast', imageUrl: 'https://img/2.jpg' }),
  anEntry({ id: 3, source: 'taz', title: 'Coast braces for storm', imageUrl: null }),
];

it('renders nothing without other sources', () => {
  expect(mount(anEntry()).querySelector('.more-sources')).toBeNull();
});

it('counts the other sources on one collapsed line', () => {
  const element = mount(anEntry({ duplicates: copies }));

  const toggle = element.querySelector('.toggle')!;
  expect(toggle.textContent).toContain('2');
  expect(toggle.getAttribute('aria-expanded')).toBe('false');
  expect(element.querySelector('.mini')).toBeNull();
});

it('expands to one mini card per source, naming its feed and title', () => {
  const element = mount(anEntry({ duplicates: copies }));
  expand(element);

  const minis = element.querySelectorAll('.mini');
  expect(minis.length).toBe(2);
  expect(minis[0].querySelector('.feed')!.textContent).toContain('NDR');
  expect(minis[0].querySelector('.title')!.textContent).toContain('Storm hits the coast');
  expect(element.querySelector('.toggle')!.getAttribute('aria-expanded')).toBe('true');
});

it('names a copy by its poster when someone other than the feed posted it', () => {
  const post = anEntry({
    id: 4,
    source: 'Home',
    poster: { name: 'Hank Green', url: null, imageUrl: null },
  });
  const element = mount(anEntry({ duplicates: [post] }));
  expand(element);

  expect(element.querySelector('.mini .feed')!.textContent).toContain('Hank Green');
});

it("shows a source's picture as its thumbnail, its favicon when it has none", () => {
  const element = mount(anEntry({ duplicates: copies }));
  expand(element);

  const [withImage, withoutImage] = Array.from(element.querySelectorAll('.mini'));
  expect(withImage.querySelector('img.thumb')!.getAttribute('src')).toBe('https://img/2.jpg');
  expect(withoutImage.querySelector('img.thumb')).toBeNull();
  expect(withoutImage.querySelector('.thumb app-favicon')).not.toBeNull();
});

it('collapses again on a second click', () => {
  const element = mount(anEntry({ duplicates: copies }));
  expand(element);
  expand(element);

  expect(element.querySelector('.mini')).toBeNull();
});

/** Mounts the footer inside a card that records whether an event of `type` reached it. */
function mountOnCard(type: 'click' | 'keydown'): { element: HTMLElement; reached: jest.Mock } {
  const card = document.createElement('div');
  const reached = jest.fn();
  card.addEventListener(type, reached);
  const element = mount(anEntry({ duplicates: copies }));
  card.appendChild(element);
  return { element, reached };
}

it('opens the source a mini card shows, and not the card it sits on', () => {
  const { element, reached } = mountOnCard('click');
  expand(element);
  reached.mockReset();

  element.querySelectorAll<HTMLButtonElement>('.mini')[1].click();

  expect(open).toHaveBeenCalledWith(copies[1]);
  expect(reached).not.toHaveBeenCalled();
});

it("keeps the toggle's click from opening the card it sits on", () => {
  const { element, reached } = mountOnCard('click');

  expand(element);

  expect(reached).not.toHaveBeenCalled();
});

it.each([
  ['.toggle', 'Enter'],
  ['.toggle', ' '],
  ['.mini', 'Enter'],
  ['.mini', ' '],
])('keeps a "%s" key "%s" from reaching the card', (selector, key) => {
  const { element, reached } = mountOnCard('keydown');
  expand(element);

  element
    .querySelector(selector)!
    .dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }));

  expect(reached).not.toHaveBeenCalled();
});
