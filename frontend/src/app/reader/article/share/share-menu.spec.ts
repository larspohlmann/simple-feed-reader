import { PostNetwork } from './post-networks.service';
import { ShareItem, TargetItem, shareMenuItems } from './share-menu';

const NETWORKS: PostNetwork[] = [
  { network: 'bluesky', handles: ['me.bsky.social'] },
  { network: 'mastodon', handles: ['@me@mastodon.social'] },
];

const kinds = (items: ShareItem[]) => items.slice(0, 4).map((item) => item.kind);
const target = (items: ShareItem[], label: string): TargetItem =>
  items.find((item): item is TargetItem => item.kind === 'target' && item.target.label === label)!;

describe('shareMenuItems', () => {
  it('lists the posts, a divider and the targets to share the page', () => {
    const items = shareMenuItems(NETWORKS, 'link');

    expect(kinds(items)).toEqual(['post', 'post', 'divider', 'target']);
    expect(items[0]).toEqual(
      expect.objectContaining({ network: 'bluesky', labelKey: 'reader.link.bluesky' }),
    );
    expect(target(items, 'X').linkOnly).toBe(false);
  });

  it('hints under the posts that a selection could be quoted', () => {
    expect(kinds(shareMenuItems(NETWORKS, 'hinted'))).toEqual(['post', 'post', 'hint', 'divider']);
  });

  it('hints at nothing without a network to post to', () => {
    expect(shareMenuItems([], 'hinted').some((item) => item.kind === 'hint')).toBe(false);
  });

  it('quotes on the networks, relabels the copy and marks the targets that take only a link', () => {
    const items = shareMenuItems(NETWORKS, 'quote');
    const copy = items.find(
      (item): item is TargetItem => item.kind === 'target' && item.target.kind === 'copy',
    )!;

    expect(items[0]).toEqual(expect.objectContaining({ labelKey: 'reader.quote.bluesky' }));
    expect(copy.target.labelKey).toBe('reader.share.quoteCopy');
    expect(copy.linkOnly).toBe(false);
    expect(target(items, 'X').linkOnly).toBe(true);
    expect(target(items, 'WhatsApp').linkOnly).toBe(false);
    expect(items.some((item) => item.kind === 'hint')).toBe(false);
  });
});
