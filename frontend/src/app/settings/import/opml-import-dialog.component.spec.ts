import { HttpStatusCode, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { DIALOG_DATA, DialogRef } from '@angular/cdk/dialog';
import { signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import { API_BASE_URL } from '../../core/api';
import { LayoutService } from '../../reader/layout.service';
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
  return { url: `https://${name}.test/feed`, title: null, state };
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
        { name: 'Deep', dropped: true, destination: [], feeds: [], folders: [] },
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
    expect(request.request.body).toEqual({ opml: OPML, parentTagName: 'P' });
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

  it('swaps the destination for the tag picker and back', () => {
    mount();

    press('opml-import-change-tags', feedRow('a1'));
    expect(feedRow('a1').querySelector('app-tag-picker')).not.toBeNull();
    expect(textOf(feedRow('a1'))).not.toContain('→ X / A');

    press('opml-import-use-folder', feedRow('a1'));
    expect(feedRow('a1').querySelector('app-tag-picker')).toBeNull();
    expect(textOf(feedRow('a1'))).toContain('→ X / A');
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
    expect(request.request.body).toEqual({ opml: OPML, parentTagName: 'P' });
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
});
