import { articleHost } from '../../../../testing/article-host-testing';
import { expandFaqDisclosures } from './reader-faq';

const host = (html: string): HTMLElement => articleHost(html, expandFaqDisclosures);

describe('expandFaqDisclosures', () => {
  it('marks a publisher disclosure and opens it', () => {
    const element = host('<details><summary>Question?</summary><p>Answer.</p></details>');
    const faq = element.querySelector('details.reader-faq');

    expect(faq).not.toBeNull();
    expect((faq as HTMLDetailsElement).open).toBe(true);
  });

  it('marks and opens every disclosure, not only the first', () => {
    const element = host(
      '<details open><summary>One</summary><p>a</p></details>' +
        '<details><summary>Two</summary><p>b</p></details>',
    );
    const boxes = element.querySelectorAll<HTMLDetailsElement>('details.reader-faq');

    expect(boxes).toHaveLength(2);
    expect([...boxes].every((box) => box.open)).toBe(true);
  });

  it('is idempotent on a second pass', () => {
    const element = host('<details><summary>Question?</summary><p>Answer.</p></details>');
    expandFaqDisclosures(element);

    expect(element.querySelectorAll('details.reader-faq')).toHaveLength(1);
  });
});
