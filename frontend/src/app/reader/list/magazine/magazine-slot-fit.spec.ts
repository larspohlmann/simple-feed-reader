import { settle } from './magazine-slot-fit';
import { EntryDto } from '../../models';

const entry = (over: Partial<EntryDto> = {}): EntryDto => ({
  id: 1,
  title: 'A headline',
  url: null,
  author: null,
  summary: null,
  excerpt: '',
  imageUrl: 'https://i/1.jpg',
  imageWidth: 900,
  imageHeight: 600,
  imageRenditions: [],
  media: [],
  attachments: [],
  categories: [],
  publishedAt: null,
  createdAt: 'x',
  subscriptionId: 1,
  source: 'S',
  faviconUrl: null,
  isHidden: false,
  isFavorite: false,
  isKept: false,
  isViewed: false,
  isShort: false,
  titleDerived: false,
  linkCards: [],
  pending: false,
  imageAspectRatio: null,
  discussionUrl: null,
  comments: null,
  ...over,
});

describe('settle', () => {
  it('gives a link slot to an entry without cards as the hero it can fill, never a link block', () => {
    expect(settle('link', entry(), 'thumb')).toBe('hero');
  });

  it('demotes a link slot down the hero ladder for an entry without a picture', () => {
    expect(settle('link', entry({ imageUrl: null, excerpt: 'Some copy' }), 'thumb')).toBe('kicker');
  });

  it('settles a post still being completed as a link block whatever its slot', () => {
    expect(settle('thumb', entry({ titleDerived: true, pending: true }), 'thumb')).toBe('link');
  });
});
