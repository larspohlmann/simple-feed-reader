import { HttpStatusCode, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { API_BASE_URL } from '../../core/api';
import { LayoutService } from '../../reader/layout.service';
import { By } from '@angular/platform-browser';
import { TagPickerComponent } from '../../reader/feeds/tag-picker/tag-picker.component';
import { TagCreator } from '../../reader/feeds/tag-picker/tag-creator.service';
import { SubscriptionRows } from '../../reader/state/subscription-rows';
import { TagsStore } from '../../reader/state/tags.store';
import { makeTag } from '../../reader/testing/tag.factory';
import { OpmlImportResult } from '../settings.models';
import { OpmlImportDialogComponent, OpmlImportDialogData } from './opml-import-dialog.component';
import { OpmlFeedState, OpmlPreview, OpmlPreviewFeed } from './opml-import.models';
import { OpmlImportSelection } from './opml-import-selection';

const BASE = 'https://api.test';
const PREVIEW = `${BASE}/api/opml/preview`;
const IMPORT = `${BASE}/api/opml/import`;
const OPML = '<opml version="2.0"><body/></opml>';

function feed(name: string, state: OpmlFeedState = 'new'): OpmlPreviewFeed {
  return { url: `https://${name}.test/feed`, title: null, siteUrl: null, state };
}

function previewOf(freeSlots = 10, destinationPrefix = 'X'): OpmlPreview {
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
              feeds: [feed('b1'), feed('b2', 'scraped')],
              folders: [],
            },
          ],
        },
        { name: 'Deep', dropped: true, destination: ['X', 'A', 'B'], feeds: [], folders: [] },
      ],
    },
  };
}

describe('OpmlImportDialogComponent', () => {
  let fixture: ComponentFixture<OpmlImportDialogComponent>;
  let http: HttpTestingController;
  const close = jest.fn();
  const initialTags = [makeTag(7, 'Mine'), makeTag(8, 'Child', { parentId: 7 })];
  const tagsStub = {
    tags: signal(initialTags),
    load: jest.fn(),
  };

  function mount(preview: OpmlPreview = previewOf()): void {
    close.mockClear();
    tagsStub.load.mockClear();
    tagsStub.tags.set(initialTags);
    TestBed.configureTestingModule({
      imports: [OpmlImportDialogComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: BASE },
        {
          provide: DIALOG_DATA,
          useValue: { opml: OPML, preview } satisfies OpmlImportDialogData,
        },
        { provide: DialogRef, useValue: { close } },
        { provide: TagsStore, useValue: tagsStub },
        { provide: SubscriptionRows, useValue: { stored: signal([]) } },
        {
          provide: LayoutService,
          useValue: { isPhone: signal(false), isCoarse: signal(false) },
        },
        { provide: TagCreator, useValue: {} },
      ],
    });
    http = TestBed.inject(HttpTestingController);
    fixture = TestBed.createComponent(OpmlImportDialogComponent);
    fixture.detectChanges();
  }

  afterEach(() => {
    jest.useRealTimers();
    http.verify();
  });

  const element = (): HTMLElement => fixture.nativeElement;
  const byTestId = <T extends HTMLElement = HTMLElement>(testId: string): T | null =>
    element().querySelector<T>(`[data-testid="${testId}"]`);
  const textOf = (node: Element | null): string =>
    node?.textContent?.replace(/\s+/g, ' ').trim() ?? '';
  const feedRow = (name: string): HTMLElement =>
    [...element().querySelectorAll<HTMLElement>('[data-testid="opml-import-feed"]')].find((row) =>
      textOf(row).includes(`${name}.test`),
    )!;
  const folderRow = (name: string): HTMLElement =>
    [...element().querySelectorAll<HTMLElement>('[data-testid="opml-import-folder"]')].find((row) =>
      textOf(row).includes(name),
    )!;
  const checkbox = (row: HTMLElement): HTMLInputElement =>
    row.querySelector('input[type="checkbox"]') as HTMLInputElement;
  const buttonOf = (testId: string, root: ParentNode = element()): HTMLButtonElement =>
    root.querySelector(`[data-testid="${testId}"] button`) as HTMLButtonElement;
  const press = (testId: string, root: ParentNode = element()): void => {
    buttonOf(testId, root).click();
    fixture.detectChanges();
  };
  const typeParent = (value: string): void => {
    const input = byTestId<HTMLInputElement>('opml-import-parent')!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  };

  it('shows the tree with new feeds ticked and the rest disabled', () => {
    mount();

    expect(checkbox(feedRow('a1')).checked).toBe(true);
    expect(checkbox(feedRow('a1')).disabled).toBe(false);
    for (const name of ['a2', 'b2']) {
      expect(checkbox(feedRow(name)).checked).toBe(false);
      expect(checkbox(feedRow(name)).disabled).toBe(true);
    }
  });

  it('the site link opens in a new tab and leaves the tick alone', () => {
    mount();
    const link = feedRow('a1').querySelector<HTMLAnchorElement>(
      '[data-testid="opml-import-site"]',
    )!;

    expect(link.getAttribute('href')).toBe('https://a1.test/');
    expect(link.target).toBe('_blank');
    expect(link.rel).toContain('noopener');

    const original = console.error.bind(console);
    const logged = jest
      .spyOn(console, 'error')
      .mockImplementation((...loggedArguments: unknown[]) => {
        if (!String(loggedArguments[0]).includes('Not implemented: navigation'))
          original(...loggedArguments);
      });
    try {
      link.click();
      fixture.detectChanges();
    } finally {
      logged.mockRestore();
    }

    expect(checkbox(feedRow('a1')).checked).toBe(true);
  });

  it('shows each new feed its destination', () => {
    mount(previewOf(10, 'P'));

    expect(textOf(feedRow('a1'))).toContain('→ P / A');
    expect(textOf(feedRow('r'))).toContain('→ No tag');
  });

  it('shows the reason on a feed that cannot be imported', () => {
    mount();

    expect(textOf(feedRow('b2'))).toContain('Scraped feeds cannot be imported');
    expect(textOf(feedRow('a2'))).toContain('Already subscribed');
    expect(feedRow('b2').classList).toContain('is-off');
  });

  it('marks a partly selected folder indeterminate', () => {
    mount();

    checkbox(feedRow('b1')).click();
    fixture.detectChanges();

    expect(checkbox(folderRow('A')).indeterminate).toBe(true);
    expect(checkbox(folderRow('A')).checked).toBe(false);
    expect(checkbox(folderRow('Deep')).disabled).toBe(true);
  });

  it('toggles every importable feed below a folder', () => {
    mount();

    checkbox(folderRow('A')).click();
    fixture.detectChanges();

    expect(checkbox(feedRow('a1')).checked).toBe(false);
    expect(checkbox(feedRow('b1')).checked).toBe(false);
    expect(checkbox(feedRow('r')).checked).toBe(true);
  });

  it('offers only top-level tags as a parent', () => {
    mount();

    const options = [...element().querySelectorAll('datalist option')].map(
      (option) => (option as HTMLOptionElement).value,
    );
    expect(options).toEqual(['Mine']);
    expect(tagsStub.load).not.toHaveBeenCalled();
  });

  it('asks for a new preview 300 ms after the parent changes', () => {
    mount();
    jest.useFakeTimers();

    typeParent('P');
    jest.advanceTimersByTime(299);
    http.expectNone(PREVIEW);
    jest.advanceTimersByTime(1);
    const request = http.expectOne(PREVIEW);
    expect(request.request.body).toEqual({ opml: OPML, parentTagName: 'P', folderNames: {} });
    request.flush(previewOf(10, 'P'));
    fixture.detectChanges();

    expect(textOf(feedRow('a1'))).toContain('→ P / A');
  });

  it('keeps the old preview when the refresh fails', () => {
    mount();
    jest.useFakeTimers();

    typeParent('P');
    jest.advanceTimersByTime(300);
    http
      .expectOne(PREVIEW)
      .flush(
        { type: 'invalid_opml', title: 'Invalid', detail: 'Bad parent', status: 422 },
        { status: HttpStatusCode.UnprocessableEntity, statusText: 'Unprocessable Entity' },
      );
    fixture.detectChanges();

    expect(textOf(byTestId('opml-import-failure'))).toContain('Bad parent');
    expect(textOf(feedRow('a1'))).toContain('→ X / A');
    expect(buttonOf('opml-import-apply').disabled).toBe(true);
  });

  const pickMode = (root: ParentNode, label: string): void => {
    const option = [
      ...root.querySelectorAll<HTMLButtonElement>('app-segmented-choice button'),
    ].find((button) => textOf(button) === label)!;
    option.click();
    fixture.detectChanges();
  };
  const openTags = (row: HTMLElement): void => {
    row.querySelector<HTMLButtonElement>('[data-testid="opml-import-tags-toggle"] button')!.click();
    fixture.detectChanges();
  };
  const toggleTagIn = (root: HTMLElement, tagId: number): void => {
    const picker = fixture.debugElement
      .queryAll(By.directive(TagPickerComponent))
      .find((candidate) => root.contains(candidate.nativeElement))!;
    picker.componentInstance.toggled.emit(tagId);
    fixture.detectChanges();
  };
  const selectionOf = (): OpmlImportSelection =>
    fixture.debugElement.injector.get(OpmlImportSelection);
  const toggleButton = (folder: string): HTMLButtonElement =>
    folderRow(folder).querySelector('[data-testid="opml-import-toggle-folder"]')!;

  it('swaps the destination for the tag picker and back', () => {
    mount();

    openTags(feedRow('a1'));
    pickMode(feedRow('a1'), 'Only tags');
    expect(feedRow('a1').querySelector('app-tag-picker')).not.toBeNull();
    expect(textOf(feedRow('a1'))).not.toContain('→ X / A');

    pickMode(feedRow('a1'), 'Folder');
    expect(feedRow('a1').querySelector('app-tag-picker')).toBeNull();
    expect(textOf(feedRow('a1'))).toContain('→ X / A');
  });

  it('picking Folder + tags shows the tag picker and the plus line', () => {
    mount();

    openTags(feedRow('a1'));
    pickMode(feedRow('a1'), 'Folder + tags');
    expect(feedRow('a1').querySelector('app-tag-picker')).not.toBeNull();
    expect(textOf(feedRow('a1').querySelector('.destination'))).toBe('→ X / A');

    toggleTagIn(feedRow('a1'), 7);

    expect(textOf(feedRow('a1').querySelector('.destination'))).toBe('→ X / A + Mine');
  });

  it('shows the mode choice only once a feed is opened for tagging', () => {
    mount();
    const toggle = (): HTMLButtonElement =>
      feedRow('a1').querySelector('[data-testid="opml-import-tags-toggle"] button')!;
    expect(feedRow('a1').querySelector('app-segmented-choice')).toBeNull();
    expect(toggle().getAttribute('aria-expanded')).toBe('false');
    expect(textOf(toggle())).toBe('Tags');

    openTags(feedRow('a1'));

    expect(feedRow('a1').querySelector('app-segmented-choice')).not.toBeNull();
    expect(toggle().getAttribute('aria-expanded')).toBe('true');
    expect(feedRow('b1').querySelector('app-segmented-choice')).toBeNull();

    openTags(feedRow('a1'));
    expect(feedRow('a1').querySelector('app-segmented-choice')).toBeNull();
  });

  it('a feed with tags renders opened', () => {
    mount();
    openTags(feedRow('a1'));
    pickMode(feedRow('a1'), 'Only tags');

    openTags(feedRow('a1'));

    expect(feedRow('a1').querySelector('app-segmented-choice')).not.toBeNull();
  });

  it('a feed that cannot be imported has no tags toggle', () => {
    mount();

    expect(feedRow('a2').querySelector('[data-testid="opml-import-tags-toggle"]')).toBeNull();
  });

  it('a dropped folder shows its own name and offers no rename', () => {
    mount();

    expect(textOf(folderRow('Deep').querySelector('.name'))).toBe('Deep');
    expect(folderRow('Deep').querySelector('[data-testid="opml-import-rename-folder"]')).toBeNull();
    expect(folderRow('Deep').querySelector('[data-testid="opml-import-reset-folder"]')).toBeNull();
  });

  it('a feed without a web address shows its host as plain text', () => {
    const odd = previewOf();
    odd.root.feeds = [{ ...feed('r'), url: 'feed://r.test/rss' }];
    mount(odd);

    expect(feedRow('r').querySelector('[data-testid="opml-import-site"]')).toBeNull();
    expect(textOf(feedRow('r').querySelector('span.host'))).toBe('r.test');
  });

  it('focuses the rename input', () => {
    mount();

    const input = startRename('A', 'A');
    fixture.detectChanges();

    expect(document.activeElement).toBe(input);
  });

  it('names only the known tags of a feed', () => {
    mount();
    openTags(feedRow('a1'));
    pickMode(feedRow('a1'), 'Folder + tags');
    toggleTagIn(feedRow('a1'), 7);
    toggleTagIn(feedRow('a1'), 99);

    expect(textOf(feedRow('a1').querySelector('.destination'))).toBe('→ X / A + Mine');
  });

  it('disables Apply to ticked once nothing is ticked', () => {
    mount();
    press('opml-import-tag-ticked');
    expect(buttonOf('opml-import-bulk-apply').disabled).toBe(false);

    press('opml-import-select-none');

    expect(buttonOf('opml-import-bulk-apply').disabled).toBe(true);
  });

  it('the chevron hides the folder rows', () => {
    mount();
    const count = (): number =>
      element().querySelectorAll('[data-testid="opml-import-feed"]').length;
    expect(count()).toBe(5);
    expect(toggleButton('A').getAttribute('aria-expanded')).toBe('true');

    toggleButton('A').click();
    fixture.detectChanges();

    expect(count()).toBe(1);
    expect(toggleButton('A').getAttribute('aria-expanded')).toBe('false');
    expect(toggleButton('A').getAttribute('aria-label')).toBe('Show or hide A');
  });

  it('the list head swaps Collapse all for Expand all', () => {
    mount();

    press('opml-import-collapse-all');
    expect(byTestId('opml-import-collapse-all')).toBeNull();
    press('opml-import-expand-all');
    expect(byTestId('opml-import-collapse-all')).not.toBeNull();
  });

  it('the bulk panel applies its tags to the ticked feeds', () => {
    mount();
    checkbox(feedRow('r')).click();
    fixture.detectChanges();

    press('opml-import-tag-ticked');
    const panel = byTestId('opml-import-bulk')!;
    pickMode(panel, 'Only tags');
    expect(panel.querySelector('app-tag-picker')).not.toBeNull();
    toggleTagIn(panel, 7);
    press('opml-import-bulk-apply', element());

    expect(byTestId('opml-import-bulk')).toBeNull();
    const request = selectionOf().toRequest(OPML, null);
    expect(request.tagIdsByFeedUrl).toEqual({
      'https://a1.test/feed': [7],
      'https://b1.test/feed': [7],
    });
  });

  it('the bulk panel warns when every feed is ticked', () => {
    mount();

    press('opml-import-tag-ticked');

    expect(textOf(byTestId('opml-import-bulk-all'))).toBe('This tags all 3 feeds in the file.');
  });

  it('the bulk panel does not warn when one is unticked', () => {
    mount();
    checkbox(feedRow('r')).click();
    fixture.detectChanges();

    press('opml-import-tag-ticked');

    expect(byTestId('opml-import-bulk')).not.toBeNull();
    expect(byTestId('opml-import-bulk-all')).toBeNull();
  });

  it('disables Tag ticked feeds when nothing is ticked', () => {
    mount();

    press('opml-import-select-none');

    expect(buttonOf('opml-import-tag-ticked').disabled).toBe(true);
  });

  it('disables import over the limit and says why', () => {
    mount(previewOf(2));

    expect(buttonOf('opml-import-apply').disabled).toBe(true);
    expect(textOf(byTestId('opml-import-counter'))).toBe(
      '3 selected, but only 2 slots are left. Clear some to import.',
    );
  });

  it('says a single slot over the limit in the singular', () => {
    mount(previewOf(1));

    expect(textOf(byTestId('opml-import-counter'))).toBe(
      '3 selected, but only 1 slot is left. Clear some to import.',
    );
  });

  it('counts a single free slot in the singular', () => {
    mount(previewOf(1));

    press('opml-import-select-none');
    checkbox(feedRow('r')).click();
    fixture.detectChanges();

    expect(textOf(byTestId('opml-import-counter'))).toBe('1 selected · 1 slot left');
  });

  it('asks for a new preview of the current parent when a tag is created', () => {
    mount();
    jest.useFakeTimers();
    typeParent('P');
    jest.advanceTimersByTime(300);
    http.expectOne(PREVIEW).flush(previewOf(10, 'P'));
    fixture.detectChanges();

    tagsStub.tags.update((tags) => [...tags, makeTag(9, 'Tech')]);
    fixture.detectChanges();
    jest.advanceTimersByTime(300);

    const request = http.expectOne(PREVIEW);
    expect(request.request.body).toEqual({ opml: OPML, parentTagName: 'P', folderNames: {} });
    request.flush(previewOf(10, 'P'));
  });

  it('asks for no new preview when the tags shrink', () => {
    mount();
    jest.useFakeTimers();

    tagsStub.tags.update((tags) => tags.slice(1));
    fixture.detectChanges();
    jest.advanceTimersByTime(300);

    http.expectNone(PREVIEW);
  });

  it('locks the parent while importing and leaves import enabled after a failure', () => {
    mount();
    jest.useFakeTimers();
    typeParent('P');
    jest.advanceTimersByTime(300);
    http.expectOne(PREVIEW).flush(previewOf(10, 'P'));
    fixture.detectChanges();

    press('opml-import-apply');
    expect(byTestId<HTMLInputElement>('opml-import-parent')!.disabled).toBe(true);
    http
      .expectOne(IMPORT)
      .flush(
        { type: 'about:blank', title: 'Down', status: 503 },
        { status: HttpStatusCode.ServiceUnavailable, statusText: 'Service Unavailable' },
      );
    fixture.detectChanges();

    expect(byTestId<HTMLInputElement>('opml-import-parent')!.disabled).toBe(false);
    expect(buttonOf('opml-import-apply').disabled).toBe(false);
  });

  it('counts the selection and the free slots', () => {
    mount();

    expect(textOf(byTestId('opml-import-counter'))).toBe('3 selected · 10 slots left');
    expect(textOf(byTestId('opml-import-apply'))).toBe('Import 3');
  });

  it('disables import when nothing is selected', () => {
    mount();

    press('opml-import-select-none');

    expect(buttonOf('opml-import-apply').disabled).toBe(true);
    press('opml-import-select-all');
    expect(buttonOf('opml-import-apply').disabled).toBe(false);
  });

  it('sends the selection and closes with the result', () => {
    mount();
    jest.useFakeTimers();
    typeParent(' P ');
    jest.advanceTimersByTime(300);
    http.expectOne(PREVIEW).flush(previewOf(10, 'P'));
    fixture.detectChanges();
    const expected = fixture.debugElement.injector.get(OpmlImportSelection).toRequest(OPML, 'P');

    press('opml-import-apply');
    const request = http.expectOne(IMPORT);
    expect(request.request.body).toEqual(expected);
    const result: OpmlImportResult = {
      imported: 3,
      alreadySubscribed: 0,
      invalid: 0,
      skippedOverLimit: 0,
    };
    request.flush(result);

    expect(close).toHaveBeenCalledWith(result);
  });

  it('drops a preview still in flight once the import starts', () => {
    mount();
    jest.useFakeTimers();
    typeParent('P');
    jest.advanceTimersByTime(300);
    const preview = http.expectOne(PREVIEW);
    typeParent('');

    press('opml-import-apply');

    expect(preview.cancelled).toBe(true);
    expect(http.expectOne(IMPORT).request.body.parentTagName).toBeNull();
    jest.advanceTimersByTime(300);
    http.expectNone(PREVIEW);
  });

  it('holds the import while a typed parent waits for its preview', () => {
    mount();
    jest.useFakeTimers();

    typeParent('P');
    expect(buttonOf('opml-import-apply').disabled).toBe(true);
    jest.advanceTimersByTime(300);
    const preview = http.expectOne(PREVIEW);
    fixture.detectChanges();
    expect(buttonOf('opml-import-apply').disabled).toBe(true);

    preview.flush(previewOf(10, 'P'));
    fixture.detectChanges();
    expect(buttonOf('opml-import-apply').disabled).toBe(false);
  });

  it('stays open with its choices when the import fails', () => {
    mount();

    press('opml-import-apply');
    http
      .expectOne(IMPORT)
      .flush(
        { type: 'validation_error', title: 'Invalid', detail: 'Too many feeds', status: 422 },
        { status: HttpStatusCode.UnprocessableEntity, statusText: 'Unprocessable Entity' },
      );
    fixture.detectChanges();

    expect(close).not.toHaveBeenCalled();
    expect(textOf(byTestId('opml-import-failure'))).toContain('Too many feeds');
    expect(checkbox(feedRow('a1')).checked).toBe(true);
    expect(buttonOf('opml-import-cancel').disabled).toBe(false);
  });

  it('closes with nothing on cancel', () => {
    mount();

    press('opml-import-cancel');

    expect(close).toHaveBeenCalledWith(undefined);
  });

  it('names the dropped folders', () => {
    mount();

    expect(textOf(byTestId('opml-import-dropped'))).toContain('Deep');
  });
  const editButton = (folder: string): HTMLButtonElement =>
    folderRow(folder).querySelector('[data-testid="opml-import-rename-folder"]')!;
  const renameInput = (row: HTMLElement): HTMLInputElement | null =>
    row.querySelector('[data-testid="opml-import-rename-input"]');
  const startRename = (folder: string, value: string): HTMLInputElement => {
    const row = folderRow(folder);
    editButton(folder).click();
    fixture.detectChanges();
    const input = renameInput(row)!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
    return input;
  };
  const key = (input: HTMLInputElement, name: string): void => {
    input.dispatchEvent(new KeyboardEvent('keydown', { key: name }));
    fixture.detectChanges();
  };

  it('committing a rename previews with the new folder names', () => {
    mount();
    jest.useFakeTimers();

    key(startRename('A', 'Gadgets'), 'Enter');
    jest.advanceTimersByTime(300);

    const request = http.expectOne(PREVIEW);
    expect(request.request.body).toEqual({
      opml: OPML,
      parentTagName: null,
      folderNames: { '0': 'Gadgets' },
    });
    request.flush(previewOf());
  });

  it('shows the planned tag name on the folder row and offers a reset after a rename', () => {
    mount();
    jest.useFakeTimers();
    expect(textOf(folderRow('A'))).toContain('A');
    expect(folderRow('A').querySelector('[data-testid="opml-import-reset-folder"]')).toBeNull();
    expect(editButton('A').getAttribute('aria-label')).toBe('Rename A');

    key(startRename('A', 'Gadgets'), 'Enter');
    jest.advanceTimersByTime(300);
    const preview = previewOf();
    preview.root.folders[0].destination = ['Gadgets'];
    http.expectOne(PREVIEW).flush(preview);
    fixture.detectChanges();

    expect(textOf(folderRow('Gadgets').querySelector('.name'))).toBe('Gadgets');
    expect(
      folderRow('Gadgets').querySelector('[data-testid="opml-import-reset-folder"]'),
    ).not.toBeNull();
  });

  it('Escape cancels a rename without a preview', () => {
    mount();
    jest.useFakeTimers();

    key(startRename('A', 'Gadgets'), 'Escape');
    jest.advanceTimersByTime(300);

    http.expectNone(PREVIEW);
    expect(element().querySelector('[data-testid="opml-import-rename-input"]')).toBeNull();
    expect(selectionOf().folderNames()).toEqual({});
  });

  it('blur commits a rename', () => {
    mount();
    jest.useFakeTimers();

    startRename('A', 'Gadgets').dispatchEvent(new Event('blur'));
    jest.advanceTimersByTime(300);

    http.expectOne(PREVIEW).flush(previewOf());
  });

  it('import waits for the preview of the current renames', () => {
    mount();
    jest.useFakeTimers();
    expect(buttonOf('opml-import-apply').disabled).toBe(false);

    key(startRename('A', 'Gadgets'), 'Enter');
    expect(buttonOf('opml-import-apply').disabled).toBe(true);
    jest.advanceTimersByTime(300);
    http.expectOne(PREVIEW).flush(previewOf());
    fixture.detectChanges();

    expect(buttonOf('opml-import-apply').disabled).toBe(false);
  });

  it('a rename and a parent change are previewed together', () => {
    mount();
    jest.useFakeTimers();

    typeParent('P');
    key(startRename('A', 'Gadgets'), 'Enter');
    jest.advanceTimersByTime(300);

    const request = http.expectOne(PREVIEW);
    expect(request.request.body).toEqual({
      opml: OPML,
      parentTagName: 'P',
      folderNames: { '0': 'Gadgets' },
    });
    request.flush(previewOf(10, 'P'));
  });

  it('ticks and tag choices survive the renames preview', () => {
    mount();
    jest.useFakeTimers();
    checkbox(feedRow('b1')).click();
    openTags(feedRow('a1'));
    pickMode(feedRow('a1'), 'Only tags');
    toggleTagIn(feedRow('a1'), 7);

    key(startRename('A', 'Gadgets'), 'Enter');
    jest.advanceTimersByTime(300);
    http.expectOne(PREVIEW).flush(previewOf());
    fixture.detectChanges();

    expect(checkbox(feedRow('b1')).checked).toBe(false);
    expect(selectionOf().toRequest(OPML, null).tagIdsByFeedUrl).toEqual({
      'https://a1.test/feed': [7],
    });
  });

  it('imports with the previewed renames', () => {
    mount();
    jest.useFakeTimers();
    key(startRename('A', 'Gadgets'), 'Enter');
    jest.advanceTimersByTime(300);
    http.expectOne(PREVIEW).flush(previewOf());
    fixture.detectChanges();

    press('opml-import-apply');

    const request = http.expectOne(IMPORT);
    expect(request.request.body.folderNames).toEqual({ '0': 'Gadgets' });
    request.flush({});
  });

  const plannedAsTechOne = (): OpmlPreview => {
    const preview = previewOf();
    preview.root.folders[0].destination = ['Tech (1)'];
    return preview;
  };

  it('blurring an unchanged editor commits nothing', () => {
    mount(plannedAsTechOne());
    jest.useFakeTimers();
    const row = folderRow('Tech (1)');

    row.querySelector<HTMLButtonElement>('[data-testid="opml-import-rename-folder"]')!.click();
    fixture.detectChanges();
    renameInput(row)!.dispatchEvent(new Event('blur'));
    fixture.detectChanges();
    jest.advanceTimersByTime(300);

    http.expectNone(PREVIEW);
    expect(selectionOf().folderNames()).toEqual({});
    expect(row.querySelector('[data-testid="opml-import-reset-folder"]')).toBeNull();
  });

  it('Enter on an unchanged editor commits nothing', () => {
    mount(plannedAsTechOne());
    jest.useFakeTimers();
    const row = folderRow('Tech (1)');

    row.querySelector<HTMLButtonElement>('[data-testid="opml-import-rename-folder"]')!.click();
    fixture.detectChanges();
    key(renameInput(row)!, 'Enter');
    jest.advanceTimersByTime(300);

    http.expectNone(PREVIEW);
    expect(selectionOf().folderNames()).toEqual({});
  });

  it('a whitespace-only rename sends no folderNames entry', () => {
    mount();
    jest.useFakeTimers();

    key(startRename('A', '   '), 'Enter');
    jest.advanceTimersByTime(300);

    http.expectNone(PREVIEW);
    expect(selectionOf().toRequest(OPML, null).folderNames).toEqual({});
  });
  it('re-committing the displayed name keeps the override and typing the files name drops it', () => {
    mount(plannedAsTechOne());
    jest.useFakeTimers();
    key(startRename('Tech (1)', 'Gadgets'), 'Enter');
    jest.advanceTimersByTime(300);
    const renamed = plannedAsTechOne();
    renamed.root.folders[0].destination = ['Gadgets'];
    http.expectOne(PREVIEW).flush(renamed);
    fixture.detectChanges();
    const row = folderRow('Gadgets');
    expect(textOf(row.querySelector('.name'))).toBe('Gadgets');

    row.querySelector<HTMLButtonElement>('[data-testid="opml-import-rename-folder"]')!.click();
    fixture.detectChanges();
    const input = renameInput(row)!;
    expect(input.value).toBe('Gadgets');
    key(input, 'Enter');
    jest.advanceTimersByTime(300);

    http.expectNone(PREVIEW);
    expect(selectionOf().folderNames()).toEqual({ '0': 'Gadgets' });

    key(startRename('Gadgets', 'A'), 'Enter');
    expect(selectionOf().folderNames()).toEqual({});
    jest.advanceTimersByTime(300);
    http.expectOne(PREVIEW).flush(plannedAsTechOne());
  });
});
