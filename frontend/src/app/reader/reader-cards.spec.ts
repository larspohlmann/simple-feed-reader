import { markInsetCards } from './reader-cards';

const host = (html: string): HTMLElement => {
  const element = document.createElement('div');
  element.innerHTML = html;
  return element;
};

// A body long enough that any small insert beside it is a minority of the
// article — the shape a real page has, and what the dominance guard expects.
const BODY = `<p>${'The article body carries the bulk of the running text here. '.repeat(20)}</p>`;

describe('markInsetCards', () => {
  it('cards a <section> insert beside the article body', () => {
    const element = host(`${BODY}<section><p>Aside</p></section>`);

    markInsetCards(element);

    expect(element.querySelector('section')?.classList.contains('reader-card')).toBe(true);
  });

  it('cards an <aside> insert beside the article body', () => {
    const element = host(`${BODY}<aside><p>Note</p></aside>`);

    markInsetCards(element);

    expect(element.querySelector('aside')?.classList.contains('reader-card')).toBe(true);
  });

  it('cards a linked-image teaser', () => {
    const element = host(
      `${BODY}<div><div><a href="https://example.com/other"><img src="https://example.com/i.png" alt=""></a></div>` +
        '<div><p>Related teaser copy that runs a little.</p></div></div>',
    );

    markInsetCards(element);

    const carded = element.querySelectorAll('.reader-card');
    expect(carded).toHaveLength(1);
    expect(carded[0].tagName).toBe('DIV');
  });

  it('cards a nested image-only promo instead of its body-block ancestor', () => {
    const element = host(
      `${BODY}<div data-body-block><p>First body paragraph beside the article image.</p>` +
        '<p>Second body paragraph that belongs to the article.</p>' +
        '<div data-image-promo><a href="https://example.com/other">' +
        '<img src="https://example.com/promo.png" alt=""></a></div></div>',
    );

    markInsetCards(element);

    expect(element.querySelector('[data-image-promo]')?.classList.contains('reader-card')).toBe(
      true,
    );
    expect(element.querySelector('[data-body-block]')?.classList.contains('reader-card')).toBe(
      false,
    );
  });

  it('does not combine a nested linked image with unrelated nested copy', () => {
    const element = host(
      `${BODY}<div data-ancestor><div data-image-owner>` +
        '<a href="https://example.com/other"><img src="https://example.com/promo.png" alt=""></a>' +
        '</div><div data-copy-owner><p>Article copy owned by a different block.</p></div></div>',
    );

    markInsetCards(element);

    expect(element.querySelector('[data-ancestor]')?.classList.contains('reader-card')).toBe(false);
  });

  it('does not card a <section> that wraps the whole article', () => {
    // Some sites (DJ Mag, Ibsen/Arday) wrap the entire body in a <section>;
    // carding that would box the whole article.
    const element = host(`<section>${BODY}</section>`);

    markInsetCards(element);

    expect(element.querySelector('.reader-card')).toBeNull();
  });

  it('does not card a titled structural <section> (has a heading)', () => {
    // Sites use <section> for ordinary structure too — a "Related articles"
    // block, a titled subsection. A heading marks it as structure, not an aside.
    const element = host(`${BODY}<section><h2>Related articles</h2><p>A link list.</p></section>`);

    markInsetCards(element);

    expect(element.querySelector('.reader-card')).toBeNull();
  });

  it('does not card a captioned article image', () => {
    // The image links to its full size and the text sits in a <figcaption>,
    // so there is no copy paragraph outside the figure — not a teaser.
    const element = host(
      `${BODY}<div><figure><a href="https://example.com/full.webp"><img src="https://example.com/i.webp" alt=""></a>` +
        '<figcaption>A photo caption.</figcaption></figure></div>',
    );

    markInsetCards(element);

    expect(element.querySelector('.reader-card')).toBeNull();
  });

  it('does not card a body block with a captioned figure and several paragraphs', () => {
    const element = host(
      `${BODY}<div data-body-block><figure>` +
        '<a href="https://example.com/full.webp"><img src="https://example.com/i.webp" alt=""></a>' +
        '<figcaption>A photo caption.</figcaption></figure>' +
        '<p>The first paragraph explains the image in the article.</p>' +
        '<p>The second paragraph continues the running article.</p>' +
        '<p>The third paragraph is still body copy.</p></div>',
    );

    markInsetCards(element);

    expect(element.querySelector('[data-body-block]')?.classList.contains('reader-card')).toBe(
      false,
    );
  });

  it('does not card a body block that merely opens with a linked image', () => {
    const element = host(
      `${BODY}<div><a href="https://example.com/o"><img src="https://example.com/i.png" alt=""></a>` +
        `<h2>Heading</h2><p>A short section under a heading.</p></div>`,
    );

    markInsetCards(element);

    expect(element.querySelector('.reader-card')).toBeNull();
  });

  it('clears a stale card tag before re-tagging (idempotent)', () => {
    const element = host(`${BODY}<section><p>Aside</p></section>`);

    markInsetCards(element);
    markInsetCards(element);

    expect(element.querySelectorAll('.reader-card')).toHaveLength(1);
  });

  it('does not nest a card inside a card', () => {
    const element = host(`${BODY}<section><aside><p>Inner</p></aside></section>`);

    markInsetCards(element);

    expect(element.querySelector('section')?.classList.contains('reader-card')).toBe(true);
    expect(element.querySelector('aside')?.classList.contains('reader-card')).toBe(false);
  });
});
