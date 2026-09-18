import {
  MIN_SENTENCE_LENGTH,
  SECTION_TARGET_FRACTION,
  TALL_BLOCK_FRACTION,
  focusUnits,
  groupIntoSections,
  sentenceSegments,
  wrapSentences,
} from './reading-sections';

function paragraph(html: string): HTMLElement {
  const p = document.createElement('p');
  p.innerHTML = html;
  return p;
}

const FOUR_SENTENCES =
  'Sentence one is long enough here. Sentence two is long enough here. ' +
  'Sentence three is long enough here. Sentence four is long enough here.';

/** A height oracle for jsdom, which has no layout: measures by tag, and by a
 *  per-element override set through a data attribute. */
function measureBy(defaults: Record<string, number>): (el: HTMLElement) => number {
  return (el) => Number(el.dataset['h'] ?? defaults[el.tagName] ?? 0);
}

describe('sentenceSegments', () => {
  it('splits plain prose at sentence boundaries', () => {
    const segments = sentenceSegments('First sentence here. Second sentence there.', 'en');
    expect(segments).toHaveLength(2);
    expect(segments[0]).toContain('First sentence here.');
    expect(segments[1]).toContain('Second sentence there.');
  });

  it('returns a single-sentence text as one segment', () => {
    expect(sentenceSegments('Just one sentence here.', 'en')).toEqual(['Just one sentence here.']);
  });

  it('keeps a German abbreviation with its sentence by merging the short fragment', () => {
    const segments = sentenceSegments('Es war z. B. sehr gut. Dann ging es weiter.', 'de');
    expect(segments).toHaveLength(2);
    expect(segments[0]).toContain('z. B.');
    expect(segments[1]).toContain('Dann ging es weiter.');
  });

  it('keeps a title abbreviation with the sentence it opens', () => {
    const segments = sentenceSegments('Dr. Smith went home. Then he slept for hours.', 'en');
    expect(segments).toHaveLength(2);
    expect(segments[0]).toContain('Dr. Smith went home.');
  });

  it('joins a trailing short fragment onto the preceding sentence', () => {
    const segments = sentenceSegments('This sentence is plenty long on its own. Ok.', 'en');
    expect(segments).toHaveLength(1);
    expect(segments[0]).toContain('Ok.');
  });

  it('leaves the text whole when Intl.Segmenter is unavailable', () => {
    const original = Intl.Segmenter;
    try {
      (Intl as unknown as { Segmenter?: unknown }).Segmenter = undefined;
      expect(sentenceSegments('First sentence here. Second sentence there.', 'en')).toEqual([
        'First sentence here. Second sentence there.',
      ]);
    } finally {
      (Intl as unknown as { Segmenter?: unknown }).Segmenter = original;
    }
  });

  it('sets the merge threshold at 20 characters', () => {
    expect(MIN_SENTENCE_LENGTH).toBe(20);
  });
});

describe('groupIntoSections', () => {
  it('returns nothing for no units', () => {
    expect(groupIntoSections([], 100)).toEqual([]);
  });

  it('keeps a single unit as one section', () => {
    expect(groupIntoSections([250], 100)).toEqual([[0]]);
  });

  it('gives each unit its own section when the target matches the unit height', () => {
    expect(groupIntoSections([100, 100, 100, 100], 100)).toEqual([[0], [1], [2], [3]]);
  });

  it('pairs units when the target spans two of them', () => {
    expect(groupIntoSections([100, 100, 100, 100], 200)).toEqual([
      [0, 1],
      [2, 3],
    ]);
  });

  it('divides by ceil(total / target), not by cutting at each target', () => {
    // total 300, target 150 -> 2 sections of ~150, borders on unit boundaries.
    expect(groupIntoSections([100, 100, 100], 150)).toEqual([[0, 1], [2]]);
  });

  it('keeps one over-tall unit as its own section', () => {
    // A single sentence taller than the target must not be cut.
    expect(groupIntoSections([500, 50, 50], 100)).toEqual([[0], [1], [2]]);
  });

  it('never leaves a section without a unit', () => {
    expect(groupIntoSections([10, 10, 100], 50)).toEqual([[0], [1], [2]]);
  });

  it('balances by cumulative height across even units', () => {
    expect(groupIntoSections([30, 30, 30, 30, 30, 30], 100)).toEqual([
      [0, 1, 2],
      [3, 4, 5],
    ]);
  });

  it('treats an unmeasured target as one section', () => {
    expect(groupIntoSections([100, 100], 0)).toEqual([[0, 1]]);
  });
});

describe('wrapSentences', () => {
  it('wraps each sentence of a paragraph in its own inline span', () => {
    const block = paragraph('This opening sentence is long enough. And a second one follows here.');
    const spans = wrapSentences(block, 'en');
    expect(spans).toHaveLength(2);
    expect(spans.every((span) => span.tagName === 'SPAN')).toBe(true);
    expect(spans[0].textContent).toContain('This opening sentence');
    expect(spans[1].textContent).toContain('second one follows');
  });

  it('preserves the paragraph text exactly', () => {
    const text = 'This opening sentence is long enough. And a second one follows here.';
    const block = paragraph(text);
    wrapSentences(block, 'en');
    expect(block.textContent).toBe(text);
  });

  it('does not split a boundary that lies inside inline markup', () => {
    const block = paragraph(
      'This opening sentence is quite long indeed. ' +
        '<em>Even this one runs long inside emphasis.</em>' +
        ' And a final one here too.',
    );
    const spans = wrapSentences(block, 'en');
    // The boundary before "Even…" sits inside <em>, so it is ignored: the <em>
    // stays whole inside the first span. Only the top-level boundary splits.
    expect(spans).toHaveLength(2);
    expect(block.querySelectorAll('em span').length).toBe(0);
    expect(spans[0].querySelector('em')).not.toBeNull();
    expect(spans[1].textContent).toContain('And a final one here too.');
  });

  it('is idempotent — a second call returns the same spans without re-wrapping', () => {
    const block = paragraph('This opening sentence is long enough. And a second one follows here.');
    const first = wrapSentences(block, 'en');
    const second = wrapSentences(block, 'en');
    expect(second).toEqual(first);
    expect(block.querySelectorAll('span.reading-sentence span.reading-sentence').length).toBe(0);
  });

  it('leaves a single-sentence paragraph unwrapped', () => {
    const block = paragraph('Just one sentence here.');
    expect(wrapSentences(block, 'en')).toEqual([]);
    expect(block.querySelector('span')).toBeNull();
  });
});

describe('the section fractions', () => {
  it('marks a block tall past half the scroller and targets a quarter', () => {
    expect(TALL_BLOCK_FRACTION).toBe(0.5);
    expect(SECTION_TARGET_FRACTION).toBe(0.25);
  });
});

describe('focusUnits', () => {
  const SCROLLER = 600; // tall past 300px, section target 150px

  it('keeps a block shorter than the tall threshold as one unit', () => {
    const block = paragraph(FOUR_SENTENCES);
    const units = focusUnits([block], SCROLLER, measureBy({ P: 200 }), 'en');
    expect(units).toEqual([[block]]);
    expect(block.querySelector('span')).toBeNull(); // not measured tall, not wrapped
  });

  it('splits a tall paragraph into sentence spans grouped into sections', () => {
    const block = paragraph(FOUR_SENTENCES);
    const units = focusUnits([block], SCROLLER, measureBy({ P: 400, SPAN: 100 }), 'en');
    expect(units).toHaveLength(3); // ceil(400/150) sections over four spans
    expect(units[0]).toHaveLength(2);
    expect(units.flat().every((el) => el.classList.contains('reading-sentence'))).toBe(true);
    expect(units.flat()).toHaveLength(4);
  });

  it('replaces a tall list with its items, grouped the same way', () => {
    const list = document.createElement('ul');
    list.innerHTML = '<li>One</li><li>Two</li><li>Three</li><li>Four</li>';
    const units = focusUnits([list], SCROLLER, measureBy({ UL: 400, LI: 100 }), 'en');
    expect(units).toHaveLength(3);
    expect(units.flat().every((el) => el.tagName === 'LI')).toBe(true);
  });

  it('keeps a tall figure as one block — a half-dimmed image looks broken', () => {
    const figure = document.createElement('figure');
    figure.innerHTML = '<img alt=""><figcaption>A caption.</figcaption>';
    const units = focusUnits([figure], SCROLLER, measureBy({ FIGURE: 500 }), 'en');
    expect(units).toEqual([[figure]]);
  });

  it('keeps a tall <pre> as one block — the highlighter owns that DOM', () => {
    const pre = document.createElement('pre');
    pre.textContent = 'line one.\nline two.\nline three is a whole sentence here.';
    const units = focusUnits([pre], SCROLLER, measureBy({ PRE: 500 }), 'en');
    expect(units).toEqual([[pre]]);
  });

  it('keeps a tall single-sentence paragraph as one block', () => {
    const block = paragraph('This one long sentence has no interior boundary at all.');
    const units = focusUnits([block], SCROLLER, measureBy({ P: 400 }), 'en');
    expect(units).toEqual([[block]]);
  });

  it('descends into a tall blockquote, splitting a tall paragraph inside it', () => {
    const quote = document.createElement('blockquote');
    const short = paragraph('A short lead-in line.');
    short.dataset['h'] = '100';
    const long = paragraph(FOUR_SENTENCES);
    long.dataset['h'] = '360';
    quote.append(short, long);
    const units = focusUnits([quote], SCROLLER, measureBy({ BLOCKQUOTE: 460, SPAN: 90 }), 'en');
    // Atoms: the short paragraph plus the long paragraph's four sentence spans.
    const atoms = units.flat();
    expect(atoms[0]).toBe(short);
    expect(atoms.slice(1).every((el) => el.classList.contains('reading-sentence'))).toBe(true);
    expect(atoms).toHaveLength(5);
  });
});
