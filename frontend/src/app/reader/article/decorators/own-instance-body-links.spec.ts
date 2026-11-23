import { articleHost } from '../../../../testing/article-host-testing';
import { OwnInstances, ownInstanceUrl } from '../../own-instance/own-instance-url';
import { openLinksOnOwnInstance } from './own-instance-body-links';

const INSTANCE = 'https://home.example';

const BODY = `
  <a id="mention" href="https://mastodon.social/@alice">@<span>alice</span></a>
  <a id="hashtag" href="https://mastodon.social/tags/rust">#<span>rust</span></a>
  <a id="status" href="https://mastodon.social/@alice/1234">a post</a>
  <a id="medium" href="https://medium.com/@author">medium.com/@author</a>
  <a id="blogPost" href="https://example.com/post/3">a blog post</a>
  <a id="note" href="#note">1</a>`;

function hrefsAfter(mastodon: string | null): Record<string, string | null> {
  const instances: OwnInstances = { mastodon, lemmy: null, lemmyHosts: new Set() };
  const host = articleHost(BODY, (element) =>
    openLinksOnOwnInstance(element, (href, text) => ownInstanceUrl(href, { text }, instances)),
  );
  return Object.fromEntries(
    Array.from(host.querySelectorAll('a'), (link) => [link.id, link.getAttribute('href')]),
  );
}

const authorize = (address: string) =>
  `${INSTANCE}/authorize_interaction?uri=${encodeURIComponent(address)}`;

describe('openLinksOnOwnInstance', () => {
  it('moves mentions, hashtags and status links and leaves other links alone', () => {
    expect(hrefsAfter(INSTANCE)).toEqual({
      mention: authorize('https://mastodon.social/@alice'),
      hashtag: `${INSTANCE}/tags/rust`,
      status: authorize('https://mastodon.social/@alice/1234'),
      medium: 'https://medium.com/@author',
      blogPost: 'https://example.com/post/3',
      note: '#note',
    });
  });

  it('changes nothing without an instance', () => {
    expect(hrefsAfter(null)).toEqual({
      mention: 'https://mastodon.social/@alice',
      hashtag: 'https://mastodon.social/tags/rust',
      status: 'https://mastodon.social/@alice/1234',
      medium: 'https://medium.com/@author',
      blogPost: 'https://example.com/post/3',
      note: '#note',
    });
  });
});
