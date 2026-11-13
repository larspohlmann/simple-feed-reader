import { SOCIAL_NETWORKS, SocialNetworkName } from '../../core/social-network';
import { SubscriptionDto, TagDto } from '../../reader/models';
import { networkOf } from '../../reader/state/social-sections';
import { TagNode, untaggedSubscriptions } from '../../reader/state/subscription-derivations';

export type VisibilityTarget =
  | { readonly kind: 'tag'; readonly tag: TagDto }
  | { readonly kind: 'network'; readonly network: SocialNetworkName }
  | { readonly kind: 'untagged' }
  | { readonly kind: 'feed'; readonly subscription: SubscriptionDto };

/** One row of the matrix, flattened out of the tree in display order. */
export interface VisibilityLine {
  /** Unique per position: a feed under two tags has two lines. */
  readonly key: string;
  readonly target: VisibilityTarget;
  readonly depth: number;
  /** Collapsing any of these hides the line. */
  readonly ancestorKeys: readonly string[];
  readonly hasChildren: boolean;
}

interface VisibilityNode {
  readonly key: string;
  readonly target: VisibilityTarget;
  readonly children: readonly VisibilityNode[];
}

/** Tags with their subtags and feeds, then each network with its feeds, then the feeds in neither. */
export function visibilityLines(
  tagTree: readonly TagNode[],
  subscriptions: readonly SubscriptionDto[],
  feedFilter: ((subscription: SubscriptionDto) => boolean) | null,
): VisibilityLine[] {
  const nodes = [
    ...tagTree.map(tagNode),
    ...networkNodes(subscriptions),
    ...untaggedNodes(subscriptions),
  ];
  const kept = feedFilter === null ? nodes : pruned(nodes, feedFilter);
  return flattened(kept, []);
}

function tagNode(node: TagNode): VisibilityNode {
  const key = `tag:${node.tag.id}`;
  return {
    key,
    target: { kind: 'tag', tag: node.tag },
    children: [
      ...node.children.map(tagNode),
      ...node.subscriptions.map((subscription) => feedNode(key, subscription)),
    ],
  };
}

function networkNodes(subscriptions: readonly SubscriptionDto[]): VisibilityNode[] {
  return SOCIAL_NETWORKS.map((network) => {
    const key = `network:${network}`;
    const feeds = subscriptions
      .filter((subscription) => networkOf(subscription) === network)
      .sort((left, right) => left.title.localeCompare(right.title));
    return {
      key,
      target: { kind: 'network', network } as const,
      children: feeds.map((subscription) => feedNode(key, subscription)),
    };
  }).filter((node) => node.children.length > 0);
}

function untaggedNodes(subscriptions: readonly SubscriptionDto[]): VisibilityNode[] {
  const feeds = untaggedSubscriptions(
    subscriptions.filter((subscription) => networkOf(subscription) === null),
  );
  if (feeds.length === 0) return [];
  return [
    {
      key: 'untagged',
      target: { kind: 'untagged' },
      children: feeds.map((subscription) => feedNode('untagged', subscription)),
    },
  ];
}

function feedNode(parentKey: string, subscription: SubscriptionDto): VisibilityNode {
  return {
    key: `${parentKey}/feed:${subscription.id}`,
    target: { kind: 'feed', subscription },
    children: [],
  };
}

/** Matching feeds, and every branch that still leads to one. */
function pruned(
  nodes: readonly VisibilityNode[],
  feedMatches: (subscription: SubscriptionDto) => boolean,
): VisibilityNode[] {
  return nodes.flatMap((node) => {
    if (node.target.kind === 'feed') return feedMatches(node.target.subscription) ? [node] : [];
    const children = pruned(node.children, feedMatches);
    return children.length > 0 ? [{ ...node, children }] : [];
  });
}

function flattened(
  nodes: readonly VisibilityNode[],
  ancestorKeys: readonly string[],
): VisibilityLine[] {
  return nodes.flatMap((node) => [
    {
      key: node.key,
      target: node.target,
      depth: ancestorKeys.length,
      ancestorKeys,
      hasChildren: node.children.length > 0,
    },
    ...flattened(node.children, [...ancestorKeys, node.key]),
  ]);
}
