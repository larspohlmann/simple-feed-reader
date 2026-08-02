import { markLeadParagraph } from './lead-paragraph';

const host = (html: string): HTMLElement => {
  const el = document.createElement('div');
  el.innerHTML = html;
  return el;
};

describe('markLeadParagraph', () => {
  it('tags the first non-empty paragraph', () => {
    const el = host('<div><p>First</p><p>Second</p></div>');

    markLeadParagraph(el);

    const tagged = el.querySelectorAll('p.lead');
    expect(tagged).toHaveLength(1);
    expect(tagged[0].textContent).toBe('First');
  });

  it('skips empty leading paragraphs', () => {
    const el = host('<p>   </p><p></p><p>Real lead</p>');

    markLeadParagraph(el);

    expect(el.querySelector('p.lead')?.textContent).toBe('Real lead');
  });

  it('reaches through nested wrappers', () => {
    const el = host('<div id="readability-page-1"><div><p>Nested lead</p></div></div>');

    markLeadParagraph(el);

    expect(el.querySelector('p.lead')?.textContent).toBe('Nested lead');
  });

  it('clears a stale tag before assigning the new one', () => {
    // A previous render tagged a paragraph that is no longer first.
    const el = host('<p>New first</p><p class="lead">Old lead</p>');

    markLeadParagraph(el);

    const tagged = el.querySelectorAll('p.lead');
    expect(tagged).toHaveLength(1);
    expect(tagged[0].textContent).toBe('New first');
  });

  it('does nothing when there is no paragraph', () => {
    const el = host('<h2>Heading only</h2>');

    markLeadParagraph(el);

    expect(el.querySelector('.lead')).toBeNull();
  });
});
