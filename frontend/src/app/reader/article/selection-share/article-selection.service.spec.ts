import { ElementRef, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { anEntry } from '../../../../testing/entry-testing';
import { EntryDto } from '../../models';
import { ArticleSelection, SELECTION_SETTLE_MS } from './article-selection.service';

describe('ArticleSelection', () => {
  const article = signal<EntryDto | null>(null);
  let body: HTMLElement;
  let outside: HTMLElement;
  let selection: ArticleSelection;

  beforeEach(() => {
    jest.useFakeTimers();
    body = document.createElement('div');
    body.innerHTML = '<p>One two three four five</p><p>Six seven eight</p>';
    outside = document.createElement('p');
    outside.textContent = 'Nine ten eleven twelve';
    document.body.append(body, outside);
    article.set(anEntry({ id: 1 }));
    TestBed.configureTestingModule({ providers: [ArticleSelection] });
    selection = TestBed.inject(ArticleSelection);
    selection.connect({ body: signal(new ElementRef(body)), article });
    TestBed.tick();
  });

  afterEach(() => {
    document.getSelection()?.removeAllRanges();
    body.remove();
    outside.remove();
    jest.useRealTimers();
  });

  const paragraph = (index: number): Node => body.querySelectorAll('p')[index].firstChild!;

  function select(start: Node, [from, to]: [number, number], end: Node = start): void {
    const range = document.createRange();
    range.setStart(start, from);
    range.setEnd(end, to);
    const current = document.getSelection()!;
    current.removeAllRanges();
    current.addRange(range);
    document.dispatchEvent(new Event('selectionchange'));
  }

  function collapse(): void {
    document.getSelection()!.collapseToStart();
    document.dispatchEvent(new Event('selectionchange'));
  }

  const settle = () => jest.advanceTimersByTime(SELECTION_SETTLE_MS);

  it('offers three selected words in the body once the selection settles', () => {
    select(paragraph(0), [0, 'One two three'.length]);
    expect(selection.quote()).toBeNull();

    settle();

    expect(selection.quote()).toEqual({ text: 'One two three', words: 3, anchorable: true });
    expect(selection.range()?.toString()).toBe('One two three');
  });

  it('ignores fewer than three words', () => {
    select(paragraph(0), [0, 'One two'.length]);
    settle();

    expect(selection.quote()).toBeNull();
  });

  it('ignores a selection outside the article body', () => {
    select(outside.firstChild!, [0, 'Nine ten eleven'.length]);
    settle();

    expect(selection.quote()).toBeNull();
  });

  it('cannot anchor a quote that spans blocks', () => {
    select(paragraph(0), ['One '.length, 'Six seven'.length], paragraph(1));
    settle();

    expect(selection.quote()).toEqual({
      text: 'two three four five\nSix seven',
      words: 6,
      anchorable: false,
    });
  });

  it('cannot anchor a quote in a social post', () => {
    article.set(
      anEntry({
        id: 1,
        social: { network: 'bluesky', isLiked: false, canLike: true, isOwn: false },
      }),
    );
    select(paragraph(0), [0, 'One two three'.length]);
    settle();

    expect(selection.quote()?.anchorable).toBe(false);
  });

  it('remembers the last quote after the selection collapses, until another selection replaces it', () => {
    select(paragraph(0), [0, 'One two three'.length]);
    settle();

    collapse();
    settle();
    expect(selection.quote()).toBeNull();
    expect(selection.remembered()?.text).toBe('One two three');

    select(paragraph(1), [0, 'Six'.length]);
    settle();
    expect(selection.remembered()).toBeNull();
  });

  it('hands out a copy of the range, so extending the selection yields a new one', () => {
    select(paragraph(0), [0, 'One two three'.length]);
    settle();
    const first = selection.range();

    select(paragraph(0), [0, 'One two three four'.length]);
    settle();

    expect(selection.range()).not.toBe(first);
    expect(first?.toString()).toBe('One two three');
    expect(selection.range()?.toString()).toBe('One two three four');
  });

  it('keeps the remembered quote when the reader selects text outside the article body', () => {
    select(paragraph(0), [0, 'One two three'.length]);
    settle();

    select(outside.firstChild!, [0, 'Nine ten eleven'.length]);
    settle();

    expect(selection.quote()).toBeNull();
    expect(selection.remembered()?.text).toBe('One two three');
  });

  it('forgets the quote when another article opens', () => {
    select(paragraph(0), [0, 'One two three'.length]);
    settle();

    article.set(anEntry({ id: 2 }));
    TestBed.tick();

    expect(selection.quote()).toBeNull();
    expect(selection.remembered()).toBeNull();
  });

  it('keeps the quote when the same article only changes', () => {
    select(paragraph(0), [0, 'One two three'.length]);
    settle();

    article.set(anEntry({ id: 1, title: 'Renamed' }));
    TestBed.tick();

    expect(selection.remembered()?.text).toBe('One two three');
  });
});
