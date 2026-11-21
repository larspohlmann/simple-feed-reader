// News feeds whose stories overlap with each other and with what the demo account follows,
// borrowed for the Trending shots and the tour's Trending scene.
export const TRENDING_FEEDS = [
  'https://www.theguardian.com/europe/rss',
  'https://www.aljazeera.com/xml/rss/all.xml',
  'https://www.cbc.ca/webfeed/rss/rss-topstories',
  'https://rss.nytimes.com/services/xml/rss/nyt/HomePage.xml',
  'https://rss.nytimes.com/services/xml/rss/nyt/World.xml',
  'https://rss.dw.com/rdf/rss-en-all',
  'https://www.france24.com/en/rss',
  'https://feeds.skynews.com/feeds/rss/world.xml',
  'https://www.independent.co.uk/news/world/rss',
  'https://abcnews.com/abcnews/internationalheadlines',
  'https://feeds.washingtonpost.com/rss/world',
  'https://www.cbsnews.com/latest/rss/world',
  'https://www.latimes.com/world-nation/rss2.0.xml',
  'https://www.politico.eu/feed/',
  'https://techcrunch.com/feed/',
  'https://www.techmeme.com/feed.xml',
  'https://www.engadget.com/rss.xml',
  'https://feeds.bbci.co.uk/news/technology/rss.xml',
];

// Below this the day window is too thin to show: a shot or the tour would record a near-empty
// list. Counted after the stories a shot leaves out (lib.mjs), so it is what the picture holds.
export const TRENDING_FLOOR = { stories: 4, leadSources: 4, titledOnFirstScreen: 4 };
