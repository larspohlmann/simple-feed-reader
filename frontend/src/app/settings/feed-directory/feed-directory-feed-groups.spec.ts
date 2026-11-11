import { FeedDirectoryFeed } from './feed-directory-rows';
import { feedGroups, feedPath } from './feed-directory-feed-groups';

const feed = (url: string, category: string): FeedDirectoryFeed => ({
  url,
  category,
  podcast: false,
  subscribed: false,
});

describe('feedGroups', () => {
  it('groups feeds by category in the order the categories first appear', () => {
    const groups = feedGroups([
      feed('https://a.de/1', 'Sports'),
      feed('https://a.de/2', 'Politics'),
      feed('https://a.de/3', 'Sports'),
    ]);

    expect(groups.map((group) => group.category)).toEqual(['Sports', 'Politics']);
    expect(groups[0].feeds.map((member) => member.url)).toEqual([
      'https://a.de/1',
      'https://a.de/3',
    ]);
  });
});

describe('feedPath', () => {
  it('drops the scheme and the site host', () => {
    expect(feedPath('https://www.spiegel.de/kultur/index.rss', 'spiegel.de')).toBe(
      '/kultur/index.rss',
    );
  });

  it('keeps the query', () => {
    expect(feedPath('https://ethz.ch/feed.html?tag=news', 'ethz.ch')).toBe('/feed.html?tag=news');
  });

  it('keeps a host that is not the site, so feeds on other hosts stay apart', () => {
    expect(feedPath('https://heiseshow.podigee.io/feed/mp3', 'heise.de')).toBe(
      'heiseshow.podigee.io/feed/mp3',
    );
  });

  it('returns an unparsable address as it is', () => {
    expect(feedPath('not a url', 'heise.de')).toBe('not a url');
  });
});
