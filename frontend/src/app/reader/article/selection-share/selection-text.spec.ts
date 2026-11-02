import { selectionText, wordsOf } from './selection-text';

describe('selectionText', () => {
  let body: HTMLElement;

  beforeEach(() => {
    body = document.createElement('div');
    document.body.append(body);
  });

  afterEach(() => body.remove());

  function textNodeWith(text: string): Text {
    const walker = document.createTreeWalker(body, NodeFilter.SHOW_TEXT);
    for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) {
      if ((node as Text).data.includes(text)) return node as Text;
    }
    throw new Error(`No text node holds "${text}"`);
  }

  function select(html: string, first: string, last: string): Range {
    body.innerHTML = html;
    const start = textNodeWith(first);
    const end = textNodeWith(last);
    const range = document.createRange();
    range.setStart(start, start.data.indexOf(first));
    range.setEnd(end, end.data.indexOf(last) + last.length);
    return range;
  }

  it('reads a selection inside one paragraph as plain text, whitespace normalised', () => {
    const range = select(
      '<p>The  quick\n <a href="#">brown</a> <em>fox</em> jumps</p>',
      'quick',
      'fox',
    );

    expect(selectionText(range)).toEqual({ text: 'quick brown fox', singleBlock: true });
  });

  it('keeps one line break between paragraphs and drops media', () => {
    const range = select(
      '<p>First para here</p><figure><img src="x.jpg" alt="A"></figure><p>Second para there</p>',
      'para here',
      'Second para',
    );

    expect(selectionText(range)).toEqual({ text: 'para here\nSecond para', singleBlock: false });
  });

  it('turns a line break into one newline inside the same block', () => {
    const range = select('<p>One line<br>next line</p>', 'One', 'next');

    expect(selectionText(range)).toEqual({ text: 'One line\nnext', singleBlock: true });
  });

  it('leaves out the text of controls and scripts', () => {
    const range = select(
      '<p>Before <button>Play</button><script>x()</script>after it</p>',
      'Before',
      'after',
    );

    expect(selectionText(range).text).toBe('Before after');
  });
});

describe('selectionText at word boundaries', () => {
  let body: HTMLElement;

  beforeEach(() => {
    body = document.createElement('div');
    document.body.append(body);
  });

  afterEach(() => body.remove());

  function rangeOver(html: string, startOffset: number, endOffset: number): Range {
    body.innerHTML = html;
    const text = body.querySelector('p')!.firstChild as Text;
    const range = document.createRange();
    range.setStart(text, startOffset);
    range.setEnd(text, endOffset);
    return range;
  }

  it('widens a start inside a word back to the start of the word', () => {
    const range = rangeOver('<p>Alpha bravo charlie delta</p>', 8, 19);

    expect(selectionText(range).text).toBe('bravo charlie');
  });

  it('widens an end inside a word forward to the end of the word', () => {
    const range = rangeOver('<p>Alpha bravo charlie delta</p>', 6, 16);

    expect(selectionText(range).text).toBe('bravo charlie');
  });

  it('keeps an apostrophe and Unicode letters inside a word', () => {
    const range = rangeOver("<p>Der Bär hat children's Bücher</p>", 9, 26);

    expect(selectionText(range).text).toBe("hat children's Bücher");
  });

  it('leaves whole words unchanged', () => {
    const range = rangeOver('<p>Alpha bravo charlie delta</p>', 6, 19);

    expect(selectionText(range).text).toBe('bravo charlie');
  });

  it('leaves a boundary at the edge of its element unchanged', () => {
    body.innerHTML = '<p>Alpha<em>bravo</em> charlie</p>';
    const range = document.createRange();
    range.setStart(body.querySelector('em')!.firstChild!, 0);
    range.setEnd(body.querySelector('p')!.lastChild!, 8);

    expect(selectionText(range).text).toBe('bravo charlie');
  });

  it('never moves the live range', () => {
    const range = rangeOver('<p>Alpha bravo charlie delta</p>', 8, 16);

    selectionText(range);

    expect([range.startOffset, range.endOffset]).toEqual([8, 16]);
  });
});

describe('wordsOf', () => {
  it('splits at any whitespace', () => {
    expect(wordsOf('  one two\nthree ')).toEqual(['one', 'two', 'three']);
    expect(wordsOf('')).toEqual([]);
  });
});
