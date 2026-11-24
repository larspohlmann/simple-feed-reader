import { Injectable, computed, signal } from '@angular/core';
import { toggled } from '../../core/toggled-set';
import { hostOf, parseUrl } from '../../shared/url-host';
import {
  OpmlImportRequest,
  OpmlImportRow,
  OpmlPreview,
  OpmlPreviewFeed,
  OpmlPreviewFolder,
} from './opml-import.models';

type FolderRow = Extract<OpmlImportRow, { kind: 'folder' }>;

interface Flattened {
  rows: OpmlImportRow[];
  importableUrls: string[];
}

function hostLabel(url: string): string {
  const parsed = parseUrl(url);
  return parsed === null ? url : hostOf(parsed);
}

function flatten(folder: OpmlPreviewFolder, depth: number, path: string): Flattened {
  const feedRows = folder.feeds.map((feed: OpmlPreviewFeed): OpmlImportRow => ({
    kind: 'feed',
    key: feed.url,
    depth,
    feed,
    host: hostLabel(feed.url),
    destination: folder.destination,
  }));
  const importableUrls = folder.feeds
    .filter((feed) => feed.state === 'new')
    .map((feed) => feed.url);
  const folderRows: OpmlImportRow[] = [];
  folder.folders.forEach((child, index) => {
    const key = `${path}${index}`;
    const below = flatten(child, depth + 1, `${key}.`);
    folderRows.push(
      {
        kind: 'folder',
        key,
        depth,
        name: child.name ?? '',
        dropped: child.dropped,
        importableUrls: below.importableUrls,
      },
      ...below.rows,
    );
    importableUrls.push(...below.importableUrls);
  });
  return { rows: [...feedRows, ...folderRows], importableUrls };
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

  private readonly flattened = computed<Flattened>(() => {
    const current = this.preview();
    return current === null ? { rows: [], importableUrls: [] } : flatten(current.root, 0, 'f');
  });
  private readonly importable = computed<ReadonlySet<string>>(
    () => new Set(this.flattened().importableUrls),
  );
  readonly folderStates = computed(() => {
    const states = new Map<string, 'all' | 'some' | 'none'>();
    const selected = this.selectedUrls();
    for (const row of this.flattened().rows) {
      if (row.kind !== 'folder') continue;
      const chosen = row.importableUrls.filter((url) => selected.has(url)).length;
      if (chosen === 0) states.set(row.key, 'none');
      else states.set(row.key, chosen === row.importableUrls.length ? 'all' : 'some');
    }
    return states;
  });

  readonly rows = computed(() => this.flattened().rows);
  readonly selected = this.selectedUrls.asReadonly();
  readonly selectedCount = computed(() => this.selectedUrls().size);
  readonly freeSlots = computed(() => this.preview()?.freeSlots ?? 0);
  readonly overLimit = computed(() => this.selectedCount() > this.freeSlots());
  readonly droppedFolders = computed(() => {
    const current = this.preview();
    return current === null ? [] : collectDroppedNames(current.root);
  });

  load(preview: OpmlPreview): void {
    const isFirstLoad = this.preview() === null;
    this.preview.set(preview);
    const importable = this.importable();
    this.selectedUrls.update((previous) =>
      isFirstLoad ? importable : new Set([...previous].filter((url) => importable.has(url))),
    );
  }

  toggleFeed(url: string): void {
    if (!this.importable().has(url)) return;
    this.selectedUrls.update((previous) => toggled(previous, url));
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
    return this.folderStates().get(row.key) ?? 'none';
  }

  selectAll(): void {
    this.selectedUrls.set(this.importable());
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
    const next = toggled(this.chosenTags(url), tagId);
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
    const feedUrls = this.rows().flatMap((row) =>
      row.kind === 'feed' && this.selectedUrls().has(row.feed.url) ? [row.feed.url] : [],
    );
    const tagIdsByFeedUrl: Record<string, number[]> = {};
    for (const url of feedUrls) {
      const chosen = this.tagChoices().get(url);
      if (chosen !== undefined)
        tagIdsByFeedUrl[url] = [...chosen].sort((left, right) => left - right);
    }
    return { opml, parentTagName, feedUrls, tagIdsByFeedUrl };
  }
}
