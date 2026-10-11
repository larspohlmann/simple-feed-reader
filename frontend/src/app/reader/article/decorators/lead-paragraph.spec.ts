import { articleHost } from '../../../../testing/article-host-testing';
import { markLeadParagraph } from './lead-paragraph';

describe('markLeadParagraph', () => {
  it('tags the first non-empty paragraph', () => {
    const element = articleHost('<div><p>First</p><p>Second</p></div>');

    markLeadParagraph(element);

    const tagged = element.querySelectorAll('p.lead');
    expect(tagged).toHaveLength(1);
    expect(tagged[0].textContent).toBe('First');
  });

  it('skips empty leading paragraphs', () => {
    const element = articleHost('<p>   </p><p></p><p>Real lead</p>');

    markLeadParagraph(element);

    expect(element.querySelector('p.lead')?.textContent).toBe('Real lead');
  });

  it('reaches through nested wrappers', () => {
    const element = articleHost('<div id="readability-page-1"><div><p>Nested lead</p></div></div>');

    markLeadParagraph(element);

    expect(element.querySelector('p.lead')?.textContent).toBe('Nested lead');
  });

  it('clears a stale tag before assigning the new one', () => {
    // A previous render tagged a paragraph that is no longer first.
    const element = articleHost('<p>New first</p><p class="lead">Old lead</p>');

    markLeadParagraph(element);

    const tagged = element.querySelectorAll('p.lead');
    expect(tagged).toHaveLength(1);
    expect(tagged[0].textContent).toBe('New first');
  });

  it('does nothing when there is no paragraph', () => {
    const element = articleHost('<h2>Heading only</h2>');

    markLeadParagraph(element);

    expect(element.querySelector('.lead')).toBeNull();
  });
});
