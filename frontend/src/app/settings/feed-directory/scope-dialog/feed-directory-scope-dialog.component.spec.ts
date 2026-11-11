import { TestBed } from '@angular/core/testing';
import { DialogRef } from '@angular/cdk/dialog';
import { of } from 'rxjs';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { FeedDirectoryApi } from '../feed-directory-api';
import { FeedDirectoryScopeService } from '../feed-directory-scope.service';
import { FeedDirectoryIndex } from '../feed-directory.models';
import { FeedDirectoryScopeDialogComponent } from './feed-directory-scope-dialog.component';

const INDEX: FeedDirectoryIndex = {
  countries: [{ code: 'DE', name: 'Germany', sites: 1329, languages: { de: 1300 } }],
  languages: { de: 1723 },
};

describe('FeedDirectoryScopeDialogComponent', () => {
  it('opens on the saved scope and closes once the picker saves', async () => {
    const close = jest.fn();
    const save = jest.fn(() => of(true));
    await TestBed.configureTestingModule({
      imports: [FeedDirectoryScopeDialogComponent, provideTranslocoTesting()],
      providers: [
        { provide: DialogRef, useValue: { close } },
        { provide: FeedDirectoryApi, useValue: { index: () => of(INDEX) } },
        {
          provide: FeedDirectoryScopeService,
          useValue: { scope: () => ({ countries: ['DE'], languages: ['de'] }), save },
        },
      ],
    }).compileComponents();
    const fixture = TestBed.createComponent(FeedDirectoryScopeDialogComponent);
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('h2').textContent).toContain(
      'Choose your feed directory',
    );
    fixture.nativeElement.querySelector('[data-test="save"]').click();

    expect(save).toHaveBeenCalledWith({ countries: ['DE'], languages: ['de'] });
    expect(close).toHaveBeenCalledWith();
  });
});
