import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Observable, of, throwError } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { FeedDirectoryApi } from '../feed-directory-api';
import { FeedDirectoryScopeService } from '../feed-directory-scope.service';
import { FeedDirectoryIndex, FeedDirectoryScope } from '../feed-directory.models';
import { FeedDirectoryScopePickerComponent } from './feed-directory-scope-picker.component';

const INDEX: FeedDirectoryIndex = {
  countries: [
    { code: 'DE', name: 'Germany', sites: 1329, languages: { de: 1300, en: 29 } },
    { code: 'AT', name: 'Austria', sites: 302, languages: { de: 302 } },
  ],
  languages: { de: 1723, en: 12525 },
};

const EMPTY_SCOPE: FeedDirectoryScope = { countries: [], languages: [] };

describe('FeedDirectoryScopePickerComponent', () => {
  let fixture: ComponentFixture<FeedDirectoryScopePickerComponent>;
  let index: FeedDirectoryIndex;
  let indexRequest: jest.Mock<Observable<FeedDirectoryIndex>, []>;
  let save: jest.Mock<Observable<boolean>, [FeedDirectoryScope]>;

  beforeEach(() => {
    index = INDEX;
    indexRequest = jest.fn(() => of(index));
  });

  async function render(initial: FeedDirectoryScope = EMPTY_SCOPE): Promise<void> {
    save = jest.fn<Observable<boolean>, [FeedDirectoryScope]>(() => of(true));
    await TestBed.configureTestingModule({
      imports: [FeedDirectoryScopePickerComponent, provideTranslocoTesting()],
      providers: [
        { provide: FeedDirectoryApi, useValue: { index: indexRequest } },
        { provide: FeedDirectoryScopeService, useValue: { save } },
      ],
    }).compileComponents();
    fixture = TestBed.createComponent(FeedDirectoryScopePickerComponent);
    fixture.componentRef.setInput('initial', initial);
    fixture.detectChanges();
  }

  function box(list: 'countries' | 'languages'): HTMLElement {
    return fixture.nativeElement.querySelector(`[data-test="${list}"]`);
  }

  function optionTexts(list: 'countries' | 'languages'): string[] {
    return Array.from(box(list).querySelectorAll<HTMLElement>('.opt')).map(
      (option) =>
        `${option.querySelector('.name')!.textContent!.trim()} ${option.querySelector('.count')!.textContent!.trim()}`,
    );
  }

  function check(list: 'countries' | 'languages', name: string): void {
    const option = Array.from(box(list).querySelectorAll<HTMLElement>('.opt')).find((candidate) =>
      candidate.textContent!.includes(name),
    )!;
    option.querySelector<HTMLInputElement>('input[type="checkbox"]')!.click();
    fixture.detectChanges();
  }

  function isChecked(list: 'countries' | 'languages', name: string): boolean {
    const option = Array.from(box(list).querySelectorAll<HTMLElement>('.opt')).find((candidate) =>
      candidate.textContent!.includes(name),
    )!;
    return option.querySelector<HTMLInputElement>('input[type="checkbox"]')!.checked;
  }

  function chipNames(list: 'countries' | 'languages'): string[] {
    return Array.from(box(list).querySelectorAll<HTMLElement>('.chip-name')).map((chip) =>
      chip.textContent!.trim(),
    );
  }

  function saveButton(): HTMLButtonElement {
    return fixture.nativeElement.querySelector('[data-test="save"] button');
  }

  it('renders a checkbox per country and language with its count, sorted by name', async () => {
    await render();

    expect(optionTexts('countries')).toEqual(['Austria 302', 'Germany 1,329']);
    expect(optionTexts('languages')).toEqual(['English 12,525', 'German 1,723']);
    expect(fixture.nativeElement.querySelectorAll('input[type="checkbox"]')).toHaveLength(4);
  });

  it('falls back to the index name for a country Intl cannot name', async () => {
    index = {
      ...INDEX,
      countries: [
        ...INDEX.countries,
        { code: 'QQ', name: 'Qland', sites: 7, languages: { de: 7 } },
      ],
    };
    await render();

    expect(optionTexts('countries')).toEqual(['Austria 302', 'Germany 1,329', 'Qland 7']);
  });

  it('falls back to the code for a malformed language code instead of failing', async () => {
    index = { ...INDEX, languages: { ...INDEX.languages, 'not a code!': 3 } };
    await render();

    expect(optionTexts('languages')).toEqual(['English 12,525', 'German 1,723', 'not a code! 3']);
  });

  it('keeps Save disabled until one country and one language are checked', async () => {
    await render();
    expect(saveButton().disabled).toBe(true);

    check('countries', 'Germany');
    expect(saveButton().disabled).toBe(true);

    check('languages', 'German');
    expect(saveButton().disabled).toBe(false);
  });

  it('narrows the country list to what the filter matches', async () => {
    await render();
    const filter = box('countries').querySelector<HTMLInputElement>('input[type="search"]')!;

    filter.value = 'germ';
    filter.dispatchEvent(new Event('input'));
    fixture.detectChanges();

    expect(optionTexts('countries')).toEqual(['Germany 1,329']);
    expect(optionTexts('languages')).toHaveLength(2);
  });

  it('saves the checked scope and emits it', async () => {
    await render();
    const saved = jest.fn();
    fixture.componentInstance.saved.subscribe(saved);
    check('countries', 'Germany');
    check('languages', 'German');

    fixture.nativeElement.querySelector('[data-test="save"]').click();
    fixture.detectChanges();

    expect(save).toHaveBeenCalledWith({ countries: ['DE'], languages: ['de'] });
    expect(saved).toHaveBeenCalledWith({ countries: ['DE'], languages: ['de'] });
    expect(fixture.nativeElement.querySelector('app-error-banner')).toBeNull();
  });

  it('shows the error banner and emits nothing when the save fails', async () => {
    await render({ countries: ['DE'], languages: ['de'] });
    save.mockReturnValue(of(false));
    const saved = jest.fn();
    fixture.componentInstance.saved.subscribe(saved);

    fixture.nativeElement.querySelector('[data-test="save"]').click();
    fixture.detectChanges();

    expect(saved).not.toHaveBeenCalled();
    expect(fixture.nativeElement.querySelector('app-error-banner').textContent).toContain(
      "Your choice wasn't saved. Try again.",
    );
  });

  it('pre-checks the initial scope and shows it as chips', async () => {
    await render({ countries: ['AT'], languages: ['en'] });

    expect(isChecked('countries', 'Austria')).toBe(true);
    expect(isChecked('countries', 'Germany')).toBe(false);
    expect(isChecked('languages', 'English')).toBe(true);
    expect(chipNames('countries')).toEqual(['Austria']);
    expect(chipNames('languages')).toEqual(['English']);
    expect(saveButton().disabled).toBe(false);
  });

  it('lists chips in the order they were picked', async () => {
    await render({ countries: ['DE'], languages: ['de'] });

    check('countries', 'Austria');

    expect(chipNames('countries')).toEqual(['Germany', 'Austria']);
  });

  it('unchecks a pick when its chip is removed', async () => {
    await render({ countries: ['AT'], languages: ['en'] });
    const chip = box('countries').querySelector<HTMLButtonElement>('.chip')!;

    expect(chip.getAttribute('aria-label')).toBe('Remove Austria');
    chip.click();
    fixture.detectChanges();

    expect(isChecked('countries', 'Austria')).toBe(false);
    expect(chipNames('countries')).toEqual([]);
    expect(saveButton().disabled).toBe(true);
  });

  it('shows the load failure with Retry instead of empty lists, and Retry loads again', async () => {
    indexRequest.mockReturnValueOnce(throwError(() => new Error('offline')));
    await render();

    const banner: HTMLElement = fixture.nativeElement.querySelector('app-error-banner');
    expect(banner.textContent).toContain("The directory couldn't be loaded.");
    expect(box('countries')).toBeNull();
    expect(fixture.nativeElement.querySelector('[data-test="save"]')).toBeNull();

    banner.querySelector<HTMLButtonElement>('button.action')!.click();
    fixture.detectChanges();

    expect(indexRequest).toHaveBeenCalledTimes(2);
    expect(fixture.nativeElement.querySelector('app-error-banner')).toBeNull();
    expect(optionTexts('countries')).toEqual(['Austria 302', 'Germany 1,329']);
  });
});
