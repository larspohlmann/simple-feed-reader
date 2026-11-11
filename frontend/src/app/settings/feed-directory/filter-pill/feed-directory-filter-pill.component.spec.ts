import { OverlayContainer } from '@angular/cdk/overlay';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import {
  FeedDirectoryFilterPillComponent,
  FeedDirectoryFilterPillChoice,
  FeedDirectoryFilterPillOption,
} from './feed-directory-filter-pill.component';

const OPTIONS: FeedDirectoryFilterPillOption[] = [
  { value: 'DE', label: 'Germany', count: '1,237', selected: true },
  { value: 'AT', label: 'Austria', selected: false },
];

describe('FeedDirectoryFilterPillComponent', () => {
  let fixture: ComponentFixture<FeedDirectoryFilterPillComponent<string>>;
  let chosen: string[];

  function render(choice: FeedDirectoryFilterPillChoice, active = false): void {
    TestBed.configureTestingModule({
      imports: [FeedDirectoryFilterPillComponent, provideTranslocoTesting()],
    });
    fixture = TestBed.createComponent(FeedDirectoryFilterPillComponent<string>);
    fixture.componentRef.setInput('label', 'All countries');
    fixture.componentRef.setInput('menuLabel', 'Countries');
    fixture.componentRef.setInput('options', OPTIONS);
    fixture.componentRef.setInput('choice', choice);
    fixture.componentRef.setInput('active', active);
    chosen = [];
    fixture.componentInstance.choose.subscribe((value) => chosen.push(value));
    fixture.detectChanges();
  }

  function pill(): HTMLButtonElement {
    return fixture.nativeElement.querySelector('.pill');
  }

  function pop(): HTMLElement | null {
    return TestBed.inject(OverlayContainer).getContainerElement().querySelector('.pop');
  }

  function openPop(): HTMLElement {
    pill().click();
    fixture.detectChanges();
    return pop()!;
  }

  afterEach(() => TestBed.inject(OverlayContainer).ngOnDestroy());

  it('shows its label and stays closed until pressed', () => {
    render('one');

    expect(pill().textContent).toContain('All countries');
    expect(pill().getAttribute('aria-expanded')).toBe('false');
    expect(pop()).toBeNull();
  });

  it('lists the options with their counts when opened', () => {
    render('one');

    const menu = openPop();

    expect(menu.getAttribute('aria-label')).toBe('Countries');
    expect(
      Array.from(menu.querySelectorAll('.option')).map((option) => [
        option.querySelector('.name')!.textContent,
        option.querySelector('.count')?.textContent,
      ]),
    ).toEqual([
      ['Germany', '1,237'],
      ['Austria', undefined],
    ]);
    expect(pill().getAttribute('aria-expanded')).toBe('true');
  });

  it('marks the picked option and closes after a single choice', () => {
    render('one');
    const menu = openPop();
    const options = menu.querySelectorAll<HTMLButtonElement>('button.option');

    expect(options[0].getAttribute('aria-pressed')).toBe('true');
    expect(options[1].getAttribute('aria-pressed')).toBe('false');
    options[1].click();
    fixture.detectChanges();

    expect(chosen).toEqual(['AT']);
    expect(pop()).toBeNull();
  });

  it('checks options and stays open for a multiple choice', () => {
    render('many');
    const boxes = openPop().querySelectorAll<HTMLInputElement>('input[type="checkbox"]');

    expect(boxes[0].checked).toBe(true);
    boxes[1].click();
    fixture.detectChanges();

    expect(chosen).toEqual(['AT']);
    expect(pop()).not.toBeNull();
  });

  it('draws as set while a filter is active', () => {
    render('many', true);

    expect(pill().classList).toContain('set');
  });

  it('counts the active picks on the pill and draws it as set', () => {
    render('many');
    fixture.componentRef.setInput('label', 'Category');
    fixture.componentRef.setInput('count', 2);
    fixture.detectChanges();

    expect(pill().textContent).toContain('Category · 2');
    expect(pill().classList).toContain('set');
  });

  it('translates an option given by key', () => {
    render('one');
    fixture.componentRef.setInput('options', [
      { value: 'az', labelKey: 'settings.feedDirectory.sort.az', selected: false },
    ]);

    expect(openPop().querySelector('.name')!.textContent).toBe('A–Z');
  });

  it('announces a dialog-like popup on the pill', () => {
    render('many');

    expect(pill().getAttribute('aria-haspopup')).toBe('dialog');
  });

  it('moves focus into the pop on open and back to the pill after a pick', () => {
    render('one');
    pill().focus();

    const menu = openPop();
    expect(document.activeElement).toBe(menu.querySelector('button.option'));

    menu.querySelectorAll<HTMLButtonElement>('button.option')[1].click();
    fixture.detectChanges();

    expect(pop()).toBeNull();
    expect(document.activeElement).toBe(pill());
  });

  it('focuses the first checkbox of a multiple choice', () => {
    render('many');

    const menu = openPop();

    expect(document.activeElement).toBe(menu.querySelector('input[type="checkbox"]'));
  });

  it('returns focus to the pill when Escape closes the pop', () => {
    render('many');
    const menu = openPop();

    menu.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', keyCode: 27, bubbles: true }));
    fixture.detectChanges();

    expect(pop()).toBeNull();
    expect(document.activeElement).toBe(pill());
  });

  it('returns focus to the pill when a click outside closes the pop', () => {
    render('many');
    openPop();

    TestBed.inject(OverlayContainer)
      .getContainerElement()
      .querySelector<HTMLElement>('.cdk-overlay-backdrop')!
      .click();
    fixture.detectChanges();

    expect(pop()).toBeNull();
    expect(document.activeElement).toBe(pill());
  });
});
