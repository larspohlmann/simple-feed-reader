import { TestBed } from '@angular/core/testing';
import { OpmlFeedState, OpmlImportRow, OpmlPreview, OpmlPreviewFeed } from './opml-import.models';
import { OpmlImportSelection } from './opml-import-selection';

function feed(url: string, state: OpmlFeedState = 'new'): OpmlPreviewFeed {
  return { url, title: null, siteUrl: null, state };
}

function previewOf(
  freeSlots = 10,
  destinationPrefix = 'X',
  b1State: OpmlFeedState = 'new',
): OpmlPreview {
  return {
    freeSlots,
    root: {
      name: null,
      dropped: false,
      destination: [],
      feeds: [feed('r')],
      folders: [
        {
          name: 'A',
          dropped: false,
          destination: [destinationPrefix, 'A'],
          feeds: [feed('a1'), feed('a2', 'subscribed')],
          folders: [
            {
              name: 'B',
              dropped: false,
              destination: [destinationPrefix, 'A', 'B'],
              feeds: [feed('b1', b1State), feed('b2', 'scraped')],
              folders: [],
            },
          ],
        },
        { name: 'Deep', dropped: true, destination: ['A', 'B', 'C'], feeds: [], folders: [] },
      ],
    },
  };
}

function folderRow(
  selection: OpmlImportSelection,
  name: string,
): OpmlImportRow & { kind: 'folder' } {
  const row = selection
    .rows()
    .find((candidate) => candidate.kind === 'folder' && candidate.name === name);
  if (row === undefined || row.kind !== 'folder') throw new Error(`no folder ${name}`);
  return row;
}

describe('OpmlImportSelection', () => {
  let selection: OpmlImportSelection;

  beforeEach(() => {
    TestBed.configureTestingModule({ providers: [OpmlImportSelection] });
    selection = TestBed.inject(OpmlImportSelection);
    selection.load(previewOf());
  });

  it('selects every new feed on first load', () => {
    expect([...selection.selected()].sort()).toEqual(['a1', 'b1', 'r']);
    expect(selection.selectedCount()).toBe(3);
  });

  it('flattens the tree in document order', () => {
    expect(selection.rows().map((row) => `${row.kind}:${row.key}:${row.depth}`)).toEqual([
      'feed:r:0',
      'folder:0:0',
      'feed:a1:1',
      'feed:a2:1',
      'folder:0.0:1',
      'feed:b1:2',
      'feed:b2:2',
      'folder:1:0',
    ]);
  });

  it('a feed links to its site from the file', () => {
    selection.load({
      freeSlots: 1,
      root: {
        name: null,
        dropped: false,
        destination: [],
        feeds: [{ ...feed('https://alwaysbeta.de/feed/'), siteUrl: 'https://alwaysbeta.de/' }],
        folders: [],
      },
    });
    const row = selection.rows()[0];
    expect(row.kind === 'feed' && row.siteHref).toBe('https://alwaysbeta.de/');
  });

  it("a feed without a site links to its feed's origin", () => {
    selection.load({
      freeSlots: 1,
      root: {
        name: null,
        dropped: false,
        destination: [],
        feeds: [feed('https://blog.example/feed.xml')],
        folders: [],
      },
    });
    expect(selection.rows().map((row) => row.kind === 'feed' && row.siteHref)).toEqual([
      'https://blog.example/',
    ]);
  });

  it('a feed whose url is no web address has no link', () => {
    selection.load({
      freeSlots: 1,
      root: {
        name: null,
        dropped: false,
        destination: [],
        feeds: [feed('not a url'), feed('feed://blog.example/rss')],
        folders: [],
      },
    });
    expect(selection.rows().map((row) => row.kind === 'feed' && row.siteHref)).toEqual([
      null,
      null,
    ]);
  });

  it('a dropped folder keeps its own name, not its ancestor path end', () => {
    const deep = selection.rows().find((row) => row.kind === 'folder' && row.dropped);
    expect(deep?.kind === 'folder' && deep.tagName).toBe('Deep');
  });

  it('a folder row knows the importable feeds below it', () => {
    expect(folderRow(selection, 'A').importableUrls).toEqual(['a1', 'b1']);
    expect(folderRow(selection, 'B').importableUrls).toEqual(['b1']);
  });

  it('reports a folder as all, some or none', () => {
    selection.toggleFeed('b1');
    expect(selection.folderState(folderRow(selection, 'A'))).toBe('some');
    expect(selection.folderState(folderRow(selection, 'B'))).toBe('none');
    selection.selectNone();
    expect(selection.folderState(folderRow(selection, 'A'))).toBe('none');
    selection.selectAll();
    expect(selection.folderState(folderRow(selection, 'A'))).toBe('all');
  });

  it('reports a folder without importable feeds as none and selects nothing when toggled', () => {
    const deep = folderRow(selection, 'Deep');
    expect(selection.folderState(deep)).toBe('none');
    selection.toggleFolder(deep);
    expect(selection.selectedCount()).toBe(3);
  });

  it('toggling a full folder clears it and a partial one fills it', () => {
    const folder = folderRow(selection, 'A');
    selection.toggleFolder(folder);
    expect([...selection.selected()]).toEqual(['r']);
    selection.toggleFolder(folder);
    expect([...selection.selected()].sort()).toEqual(['a1', 'b1', 'r']);
    selection.toggleFeed('a1');
    selection.toggleFolder(folder);
    expect(selection.folderState(folder)).toBe('all');
  });

  it('never selects a feed that is not new', () => {
    selection.toggleFeed('a2');
    selection.selectNone();
    selection.selectAll();
    expect(selection.selected().has('a2')).toBe(false);
    expect(selection.selected().has('b2')).toBe(false);
  });

  it('is over the limit when more are selected than slots are free', () => {
    selection.load(previewOf(2));
    expect(selection.freeSlots()).toBe(2);
    expect(selection.overLimit()).toBe(true);
    selection.toggleFeed('r');
    expect(selection.overLimit()).toBe(false);
  });

  it('names the dropped folders', () => {
    expect(selection.droppedFolders()).toEqual(['Deep']);
  });

  it('keeps the selection and the changed tags across a new preview', () => {
    selection.toggleFeed('r');
    selection.setMode('a1', 'tagsOnly');
    selection.toggleTag('a1', 7);
    selection.load(previewOf(10, 'Y'));
    expect([...selection.selected()].sort()).toEqual(['a1', 'b1']);
    expect([...selection.chosenTags('a1')]).toEqual([7]);
  });

  it('drops a feed the new preview no longer offers', () => {
    selection.load(previewOf(10, 'X', 'subscribed'));
    expect(selection.selected().has('b1')).toBe(false);
  });

  it('keeps row keys identical across a refreshed preview of the same tree', () => {
    const before = selection.rows().map((row) => row.key);
    selection.load(previewOf(10, 'Y'));
    expect(selection.rows().map((row) => row.key)).toEqual(before);
  });

  it('builds the request from the selection', () => {
    selection.setMode('b1', 'tagsOnly');
    selection.toggleTag('b1', 9);
    selection.toggleTag('b1', 3);
    selection.setMode('r', 'tagsOnly');
    selection.toggleFeed('r');
    expect(selection.toRequest('<opml/>', null)).toEqual({
      opml: '<opml/>',
      parentTagName: null,
      feedUrls: ['a1', 'b1'],
      tagIdsByFeedUrl: { b1: [3, 9] },
      addedTagIdsByFeedUrl: {},
      folderNames: {},
    });
  });

  it('sends a named parent tag as given', () => {
    expect(selection.toRequest('<opml/>', 'Imported').parentTagName).toBe('Imported');
  });

  it('a feed starts in folder mode', () => {
    expect(selection.modeOf('a1')).toBe('folder');
  });

  it('setMode folder clears its tags', () => {
    selection.setMode('a1', 'tagsOnly');
    selection.toggleTag('a1', 4);
    selection.setMode('a1', 'folder');
    expect(selection.modeOf('a1')).toBe('folder');
    expect([...selection.chosenTags('a1')]).toEqual([]);
    expect(selection.toRequest('<opml/>', null).tagIdsByFeedUrl).toEqual({});
  });

  it('an empty tags only choice is sent as untagged', () => {
    selection.setMode('a1', 'tagsOnly');
    expect(selection.modeOf('a1')).toBe('tagsOnly');
    expect(selection.toRequest('<opml/>', null).tagIdsByFeedUrl).toEqual({ a1: [] });
  });

  it('toggles a tag off again', () => {
    selection.setMode('a1', 'tagsOnly');
    selection.toggleTag('a1', 4);
    selection.toggleTag('a1', 4);
    expect([...selection.chosenTags('a1')]).toEqual([]);
  });

  it('ignores a tag toggle on a feed in folder mode', () => {
    selection.toggleTag('a1', 4);
    expect(selection.modeOf('a1')).toBe('folder');
    expect([...selection.chosenTags('a1')]).toEqual([]);
  });

  it('switching between the tag modes keeps the chosen tags', () => {
    selection.setMode('a1', 'tagsOnly');
    selection.toggleTag('a1', 4);
    selection.setMode('a1', 'folderPlusTags');
    expect(selection.modeOf('a1')).toBe('folderPlusTags');
    expect([...selection.chosenTags('a1')]).toEqual([4]);
    selection.setMode('a1', 'tagsOnly');
    expect([...selection.chosenTags('a1')]).toEqual([4]);
  });

  it('applyToTicked writes the mode and tags onto every ticked feed and replaces earlier choices', () => {
    selection.toggleFeed('r');
    selection.setMode('a1', 'tagsOnly');
    selection.toggleTag('a1', 1);
    selection.setMode('r', 'tagsOnly');
    selection.toggleTag('r', 5);
    selection.applyToTicked('folderPlusTags', new Set([2, 3]));
    expect(selection.modeOf('a1')).toBe('folderPlusTags');
    expect([...selection.chosenTags('a1')]).toEqual([2, 3]);
    expect(selection.modeOf('b1')).toBe('folderPlusTags');
    expect([...selection.chosenTags('b1')]).toEqual([2, 3]);
    expect(selection.modeOf('r')).toBe('tagsOnly');
    expect([...selection.chosenTags('r')]).toEqual([5]);
  });

  it('applyToTicked folder clears the ticked feeds choices', () => {
    selection.setMode('a1', 'tagsOnly');
    selection.setMode('b1', 'folderPlusTags');
    selection.applyToTicked('folder', new Set([1]));
    expect(selection.modeOf('a1')).toBe('folder');
    expect(selection.modeOf('b1')).toBe('folder');
    expect([...selection.chosenTags('a1')]).toEqual([]);
  });

  it('allTicked is true only when every importable feed is ticked', () => {
    expect(selection.allTicked()).toBe(true);
    selection.toggleFeed('a1');
    expect(selection.allTicked()).toBe(false);
    selection.selectAll();
    expect(selection.allTicked()).toBe(true);
    selection.selectNone();
    expect(selection.allTicked()).toBe(false);
  });

  it('allTicked is false when nothing is importable', () => {
    selection.load(previewOf(10, 'X', 'subscribed'));
    selection.toggleFeed('a1');
    selection.toggleFeed('r');
    expect(selection.allTicked()).toBe(false);
  });

  it('toRequest splits the two tag modes into their maps', () => {
    selection.setMode('a1', 'tagsOnly');
    selection.toggleTag('a1', 8);
    selection.toggleTag('a1', 2);
    selection.setMode('b1', 'folderPlusTags');
    selection.toggleTag('b1', 6);
    selection.toggleTag('b1', 5);
    expect(selection.toRequest('<opml/>', null)).toEqual({
      opml: '<opml/>',
      parentTagName: null,
      feedUrls: ['r', 'a1', 'b1'],
      tagIdsByFeedUrl: { a1: [2, 8] },
      addedTagIdsByFeedUrl: { b1: [5, 6] },
      folderNames: {},
    });
  });

  it('a choice survives a re-preview of the same file', () => {
    selection.setMode('a1', 'folderPlusTags');
    selection.load(previewOf());
    selection.load(previewOf());
    expect(selection.modeOf('a1')).toBe('folderPlusTags');
  });
  it('collapsing a folder hides its feeds and subfolders but keeps the folder row', () => {
    selection.toggleCollapsed('0');
    const keys = selection.visibleRows().map((row) => row.key);
    expect(keys).toContain('0');
    expect(keys).not.toContain('a1');
    expect(keys).not.toContain('0.0');
    expect(keys).not.toContain('b1');
    expect(keys).toContain('r');
    expect(selection.isCollapsed('0')).toBe(true);
  });

  it('collapse state survives a re-preview', () => {
    selection.toggleCollapsed('0');
    selection.load(previewOf(10, 'Y'));
    expect(selection.isCollapsed('0')).toBe(true);
    expect(selection.visibleRows().some((row) => row.key === 'a1')).toBe(false);
  });

  it('collapseAll then expandAll restores every row', () => {
    const all = selection.rows().map((row) => row.key);
    selection.collapseAll();
    expect(selection.anyExpanded()).toBe(false);
    expect(selection.visibleRows().every((row) => row.kind === 'folder' || row.depth === 0)).toBe(
      true,
    );
    selection.expandAll();
    expect(selection.anyExpanded()).toBe(true);
    expect(selection.visibleRows().map((row) => row.key)).toEqual(all);
  });

  it('a feed with a tag choice that a re-preview drops is absent from the request maps', () => {
    selection.setMode('b1', 'tagsOnly');
    selection.toggleTag('b1', 4);
    selection.setMode('a1', 'folderPlusTags');
    selection.toggleTag('a1', 5);
    selection.load(previewOf(10, 'X', 'subscribed'));
    const request = selection.toRequest('<opml/>', null);
    expect(request.tagIdsByFeedUrl).toEqual({});
    expect(request.addedTagIdsByFeedUrl).toEqual({ a1: [5] });
  });

  it('an unticked feed with a choice is in neither map and sends its choice once re-ticked', () => {
    selection.setMode('a1', 'tagsOnly');
    selection.toggleTag('a1', 3);
    selection.toggleFeed('a1');
    const unticked = selection.toRequest('<opml/>', null);
    expect(unticked.tagIdsByFeedUrl).toEqual({});
    expect(unticked.addedTagIdsByFeedUrl).toEqual({});
    selection.toggleFeed('a1');
    expect(selection.toRequest('<opml/>', null).tagIdsByFeedUrl).toEqual({ a1: [3] });
  });

  it('renaming back to the files name drops the override', () => {
    const folder = folderRow(selection, 'A');
    selection.renameFolder(folder, 'Gadgets');
    expect(selection.isRenamed(folder)).toBe(true);

    selection.renameFolder(folder, ' A ');

    expect(selection.isRenamed(folder)).toBe(false);
    expect(selection.folderNames()).toEqual({});
  });

  it('a blank rename drops the override', () => {
    const folder = folderRow(selection, 'A');
    selection.renameFolder(folder, 'Gadgets');

    selection.renameFolder(folder, '   ');

    expect(selection.folderNames()).toEqual({});
  });

  it('resetFolder drops the override', () => {
    const folder = folderRow(selection, 'B');
    selection.renameFolder(folder, 'Gadgets');

    selection.resetFolder(folder);

    expect(selection.isRenamed(folder)).toBe(false);
  });

  it('toRequest carries folderNames by position, trimmed', () => {
    selection.renameFolder(folderRow(selection, 'A'), ' Gadgets ');
    selection.renameFolder(folderRow(selection, 'B'), 'Phones');

    expect(selection.toRequest('<opml/>', null).folderNames).toEqual({
      '0': 'Gadgets',
      '0.0': 'Phones',
    });
  });

  it('the folder row shows the planned tag name', () => {
    expect(folderRow(selection, 'A').tagName).toBe('A');
    expect(folderRow(selection, 'Deep').tagName).toBe('Deep');
    selection.load({
      freeSlots: 10,
      root: {
        name: null,
        dropped: false,
        destination: [],
        feeds: [],
        folders: [
          { name: 'Tech', dropped: false, destination: ['Tech (1)'], feeds: [], folders: [] },
        ],
      },
    });

    expect(folderRow(selection, 'Tech').tagName).toBe('Tech (1)');
  });
});
