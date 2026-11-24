import { Injectable, computed, signal } from '@angular/core';
import {
  OpmlImportRequest,
  OpmlImportRow,
  OpmlPreview,
  OpmlPreviewFeed,
  OpmlPreviewFolder,
} from './opml-import.models';

type FolderRow = Extract<OpmlImportRow, { kind: 'folder' }>;

function importableUrlsBelow(folder: OpmlPreviewFolder): string[] {
  return [
    ...folder.feeds.filter((feed) => feed.state === 'new').map((feed) => feed.url),
    ...folder.folders.flatMap(importableUrlsBelow),
  ];
}

function flatten(folder: OpmlPreviewFolder, depth: number, path: string): OpmlImportRow[] {
  const feedRows = folder.feeds.map((feed: OpmlPreviewFeed): OpmlImportRow => ({
    kind: 'feed',
    key: feed.url,
    depth,
    feed,
    destination: folder.destination,
  }));
  const folderRows = folder.folders.flatMap((child, index) => {
    const key = `${path}${index}`;
    const row: OpmlImportRow = {
      kind: 'folder',
      key,
      depth,
      name: child.name ?? '',
      dropped: child.dropped,
      importableUrls: importableUrlsBelow(child),
    };
    return [row, ...flatten(child, depth + 1, `${key}.`)];
  });
  return [...feedRows, ...folderRows];
}

function collectDroppedNames(folder: OpmlPreviewFolder): string[] {
  return folder.folders.flatMap((child) => [
    ...(child.dropped ? [child.name ?? ''] : []),
    ...collectDroppedNames(child),
  ]);
}

@Injectable()
export class OpmlImportSelection {
  private readonly preview = signal<OpmlPreview | null>(null);
  private readonly selectedUrls = signal<ReadonlySet<string>>(new Set());
  private readonly tagChoices = signal<ReadonlyMap<string, ReadonlySet<number>>>(new Map());

  readonly rows = computed<OpmlImportRow[]>(() => {
    const current = this.preview();
    return current === null ? [] : flatten(current.root, 0, 'f');
  });
  readonly selected = this.selectedUrls.asReadonly();
  readonly selectedCount = computed(() => this.selectedUrls().size);
  readonly freeSlots = computed(() => this.preview()?.freeSlots ?? 0);
  readonly overLimit = computed(() => this.selectedCount() > this.freeSlots());
  readonly droppedFolders = computed(() => {
    const current = this.preview();
    return current === null ? [] : collectDroppedNames(current.root);
  });

  load(preview: OpmlPreview): void {
    const importable = new Set(importableUrlsBelow(preview.root));
    const isFirstLoad = this.preview() === null;
    this.selectedUrls.update((previous) =>
      isFirstLoad ? importable : new Set([...previous].filter((url) => importable.has(url))),
    );
    this.preview.set(preview);
  }

  toggleFeed(url: string): void {
    if (!this.importableUrls().has(url)) return;
    this.selectedUrls.update((previous) => {
      const next = new Set(previous);
      if (!next.delete(url)) next.add(url);
      return next;
    });
  }

  toggleFolder(row: FolderRow): void {
    const clears = this.folderState(row) === 'all';
    this.selectedUrls.update((previous) => {
      const next = new Set(previous);
      for (const url of row.importableUrls) {
        if (clears) next.delete(url);
        else next.add(url);
      }
      return next;
    });
  }

  folderState(row: FolderRow): 'all' | 'some' | 'none' {
    const chosen = row.importableUrls.filter((url) => this.selectedUrls().has(url)).length;
    if (chosen === 0) return 'none';
    return chosen === row.importableUrls.length ? 'all' : 'some';
  }

  selectAll(): void {
    this.selectedUrls.set(this.importableUrls());
  }

  selectNone(): void {
    this.selectedUrls.set(new Set());
  }

  isChanged(url: string): boolean {
    return this.tagChoices().has(url);
  }

  chosenTags(url: string): ReadonlySet<number> {
    return this.tagChoices().get(url) ?? new Set();
  }

  changeTags(url: string): void {
    this.tagChoices.update((previous) => new Map(previous).set(url, new Set()));
  }

  toggleTag(url: string, tagId: number): void {
    const next = new Set(this.chosenTags(url));
    if (!next.delete(tagId)) next.add(tagId);
    this.tagChoices.update((previous) => new Map(previous).set(url, next));
  }

  useFolder(url: string): void {
    this.tagChoices.update((previous) => {
      const next = new Map(previous);
      next.delete(url);
      return next;
    });
  }

  toRequest(opml: string, parentTagName: string | null): OpmlImportRequest {
    const feedUrls = this.rows()
      .filter((row) => row.kind === 'feed' && this.selectedUrls().has(row.feed.url))
      .map((row) => row.key);
    const tagIdsByFeedUrl: Record<string, number[]> = {};
    for (const url of feedUrls) {
      const chosen = this.tagChoices().get(url);
      if (chosen !== undefined)
        tagIdsByFeedUrl[url] = [...chosen].sort((left, right) => left - right);
    }
    const parent = parentTagName?.trim() ?? '';
    return { opml, parentTagName: parent === '' ? null : parent, feedUrls, tagIdsByFeedUrl };
  }

  private importableUrls(): ReadonlySet<string> {
    const current = this.preview();
    return new Set(current === null ? [] : importableUrlsBelow(current.root));
  }
}
