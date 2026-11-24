export type OpmlFeedState = 'new' | 'subscribed' | 'scraped' | 'invalid_url';

export interface OpmlPreviewFeed {
  url: string;
  title: string | null;
  state: OpmlFeedState;
}

export interface OpmlPreviewFolder {
  name: string | null;
  dropped: boolean;
  destination: string[];
  feeds: OpmlPreviewFeed[];
  folders: OpmlPreviewFolder[];
}

export interface OpmlPreview {
  freeSlots: number;
  root: OpmlPreviewFolder;
}

export interface OpmlImportRequest {
  opml: string;
  parentTagName: string | null;
  feedUrls: string[];
  tagIdsByFeedUrl: Record<string, number[]>;
}

export type OpmlImportRow =
  | {
      kind: 'folder';
      key: string;
      depth: number;
      name: string;
      dropped: boolean;
      importableUrls: string[];
    }
  | {
      kind: 'feed';
      key: string;
      depth: number;
      feed: OpmlPreviewFeed;
      host: string;
      destination: string[];
    };
