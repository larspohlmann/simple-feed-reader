import { articleHost } from '../../../../testing/article-host-testing';
import { openMastodonLinksOnOwnInstance } from './own-instance-body-links';

const INSTANCE = 'https://home.example';

const BODY = `
  <a id="mention" href="https://mastodon.social/@alice">@<span>alice</span></a>
  <a id="hashtag" href="https://mastodon.social/tags/rust">#<span>rust</span></a>
  <a id="medium" href="https://medium.com/@author">medium.com/@author</a>
  <a id="note" href="#note">1</a>`;

function hrefsAfter(instance: string | null): Record<string, string | null> {
  const host = articleHost(BODY, (element) => openMastodonLinksOnOwnInstance(element, instance));
  return Object.fromEntries(
    Array.from(host.querySelectorAll('a'), (link) => [link.id, link.getAttribute('href')]),
  );
}

describe('openMastodonLinksOnOwnInstance', () => {
  it('opens mentions and hashtags on the reader instance and leaves other links alone', () => {
    expect(hrefsAfter(INSTANCE)).toEqual({
      mention: `${INSTANCE}/authorize_interaction?uri=${encodeURIComponent('https://mastodon.social/@alice')}`,
      hashtag: `${INSTANCE}/tags/rust`,
      medium: 'https://medium.com/@author',
      note: '#note',
    });
  });

  it('changes nothing without an instance', () => {
    expect(hrefsAfter(null)).toEqual({
      mention: 'https://mastodon.social/@alice',
      hashtag: 'https://mastodon.social/tags/rust',
      medium: 'https://medium.com/@author',
      note: '#note',
    });
  });
});
