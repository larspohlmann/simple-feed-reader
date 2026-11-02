import { ElementRef, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { StubArticlePageSource } from '../../../../testing/article-page-source-testing';
import { ARTICLE_PAGE_SOURCE } from '../article-page/article-page-source';
import { ArticleSelection, SELECTION_SETTLE_MS } from './article-selection.service';

describe('ArticleSelection', () => {
  let page: StubArticlePageSource;
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
    page = new StubArticlePageSource();
    page.key.set('entry:1');
    TestBed.configureTestingModule({
      providers: [ArticleSelection, { provide: ARTICLE_PAGE_SOURCE, useValue: page }],
    });
    selection = TestBed.inject(ArticleSelection);
    selection.connect(signal(new ElementRef(body)));
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
    page.socialPost.set(true);
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

  it('keeps the same quote and range when the identical selection settles again', () => {
    select(paragraph(0), [0, 'One two three'.length]);
    settle();
    const quote = selection.quote();
    const range = selection.range();

    select(paragraph(0), [0, 'One two three'.length]);
    settle();

    expect(selection.quote()).toBe(quote);
    expect(selection.range()).toBe(range);
  });

  it('hands out the new range when the same words are selected elsewhere', () => {
    (paragraph(1) as Text).data = 'One two three';
    select(paragraph(0), [0, 'One two three'.length]);
    settle();

    select(paragraph(1), [0, 'One two three'.length]);
    settle();

    expect(selection.range()?.startContainer).toBe(paragraph(1));
  });

  it('hands out a new quote when the same text turns unanchorable', () => {
    select(paragraph(0), [0, 'One two three'.length]);
    settle();
    page.socialPost.set(true);

    select(paragraph(0), [0, 'One two three'.length]);
    settle();

    expect(selection.quote()?.anchorable).toBe(false);
  });

  it('keeps the remembered quote when the reader selects text outside the article body', () => {
    select(paragraph(0), [0, 'One two three'.length]);
    settle();

    select(outside.firstChild!, [0, 'Nine ten eleven'.length]);
    settle();

    expect(selection.quote()).toBeNull();
    expect(selection.remembered()?.text).toBe('One two three');
  });

  it('forgets the quote when another key opens', () => {
    select(paragraph(0), [0, 'One two three'.length]);
    settle();

    page.key.set('entry:2');
    TestBed.tick();

    expect(selection.quote()).toBeNull();
    expect(selection.remembered()).toBeNull();
  });

  it('keeps the quote when the same key is set again', () => {
    select(paragraph(0), [0, 'One two three'.length]);
    settle();

    page.key.set('entry:1');
    TestBed.tick();

    expect(selection.remembered()?.text).toBe('One two three');
  });

  it('collapses the page selection when released, and keeps the remembered quote', () => {
    select(paragraph(0), [0, 'One two three'.length]);
    settle();

    selection.releasePageSelection();
    document.dispatchEvent(new Event('selectionchange'));
    settle();

    expect(document.getSelection()?.rangeCount).toBe(0);
    expect(selection.quote()).toBeNull();
    expect(selection.remembered()?.text).toBe('One two three');
  });
});
