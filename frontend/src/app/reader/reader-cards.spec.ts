import { markInsetCards } from './reader-cards';

const host = (html: string): HTMLElement => {
  const el = document.createElement('div');
  el.innerHTML = html;
  return el;
};

// A body long enough that any small insert beside it is a minority of the
// article — the shape a real page has, and what the dominance guard expects.
const BODY = `<p>${'The article body carries the bulk of the running text here. '.repeat(20)}</p>`;

describe('markInsetCards', () => {
  it('cards a <section> insert beside the article body', () => {
    const el = host(`${BODY}<section><p>Aside</p></section>`);

    markInsetCards(el);

    expect(el.querySelector('section')?.classList.contains('reader-card')).toBe(true);
  });

  it('cards an <aside> insert beside the article body', () => {
    const el = host(`${BODY}<aside><p>Note</p></aside>`);

    markInsetCards(el);

    expect(el.querySelector('aside')?.classList.contains('reader-card')).toBe(true);
  });

  it('cards a linked-image teaser', () => {
    const el = host(
      `${BODY}<div><div><a href="https://example.com/other"><img src="https://example.com/i.png" alt=""></a></div>` +
        '<div><p>Related teaser copy that runs a little.</p></div></div>',
    );

    markInsetCards(el);

    const carded = el.querySelectorAll('.reader-card');
    expect(carded).toHaveLength(1);
    expect(carded[0].tagName).toBe('DIV');
  });

  it('cards a nested image-only promo instead of its body-block ancestor', () => {
    const el = host(
      `${BODY}<div data-body-block><p>First body paragraph beside the article image.</p>` +
        '<p>Second body paragraph that belongs to the article.</p>' +
        '<div data-image-promo><a href="https://example.com/other">' +
        '<img src="https://example.com/promo.png" alt=""></a></div></div>',
    );

    markInsetCards(el);

    expect(el.querySelector('[data-image-promo]')?.classList.contains('reader-card')).toBe(true);
    expect(el.querySelector('[data-body-block]')?.classList.contains('reader-card')).toBe(false);
  });

  it('does not combine a nested linked image with unrelated nested copy', () => {
    const el = host(
      `${BODY}<div data-ancestor><div data-image-owner>` +
        '<a href="https://example.com/other"><img src="https://example.com/promo.png" alt=""></a>' +
        '</div><div data-copy-owner><p>Article copy owned by a different block.</p></div></div>',
    );

    markInsetCards(el);

    expect(el.querySelector('[data-ancestor]')?.classList.contains('reader-card')).toBe(false);
  });

  it('does not card a <section> that wraps the whole article', () => {
    // Some sites (DJ Mag, Ibsen/Arday) wrap the entire body in a <section>;
    // carding that would box the whole article.
    const el = host(`<section>${BODY}</section>`);

    markInsetCards(el);

    expect(el.querySelector('.reader-card')).toBeNull();
  });

  it('does not card a titled structural <section> (has a heading)', () => {
    // Sites use <section> for ordinary structure too — a "Related articles"
    // block, a titled subsection. A heading marks it as structure, not an aside.
    const el = host(`${BODY}<section><h2>Related articles</h2><p>A link list.</p></section>`);

    markInsetCards(el);

    expect(el.querySelector('.reader-card')).toBeNull();
  });

  it('does not card a captioned article image', () => {
    // The image links to its full size and the text sits in a <figcaption>,
    // so there is no copy paragraph outside the figure — not a teaser.
    const el = host(
      `${BODY}<div><figure><a href="https://example.com/full.webp"><img src="https://example.com/i.webp" alt=""></a>` +
        '<figcaption>A photo caption.</figcaption></figure></div>',
    );

    markInsetCards(el);

    expect(el.querySelector('.reader-card')).toBeNull();
  });

  it('does not card a body block with a captioned figure and several paragraphs', () => {
    const el = host(
      `${BODY}<div data-body-block><figure>` +
        '<a href="https://example.com/full.webp"><img src="https://example.com/i.webp" alt=""></a>' +
        '<figcaption>A photo caption.</figcaption></figure>' +
        '<p>The first paragraph explains the image in the article.</p>' +
        '<p>The second paragraph continues the running article.</p>' +
        '<p>The third paragraph is still body copy.</p></div>',
    );

    markInsetCards(el);

    expect(el.querySelector('[data-body-block]')?.classList.contains('reader-card')).toBe(false);
  });

  it('does not card a body block that merely opens with a linked image', () => {
    const el = host(
      `${BODY}<div><a href="https://example.com/o"><img src="https://example.com/i.png" alt=""></a>` +
        `<h2>Heading</h2><p>A short section under a heading.</p></div>`,
    );

    markInsetCards(el);

    expect(el.querySelector('.reader-card')).toBeNull();
  });

  it('clears a stale card tag before re-tagging (idempotent)', () => {
    const el = host(`${BODY}<section><p>Aside</p></section>`);

    markInsetCards(el);
    markInsetCards(el);

    expect(el.querySelectorAll('.reader-card')).toHaveLength(1);
  });

  it('does not nest a card inside a card', () => {
    const el = host(`${BODY}<section><aside><p>Inner</p></aside></section>`);

    markInsetCards(el);

    expect(el.querySelector('section')?.classList.contains('reader-card')).toBe(true);
    expect(el.querySelector('aside')?.classList.contains('reader-card')).toBe(false);
  });
});
