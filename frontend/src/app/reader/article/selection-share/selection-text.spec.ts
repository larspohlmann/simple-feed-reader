import { selectionText, wordCount } from './selection-text';

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

describe('wordCount', () => {
  it('counts the words between any whitespace', () => {
    expect(wordCount('  one two\nthree ')).toBe(3);
    expect(wordCount('')).toBe(0);
  });
});
