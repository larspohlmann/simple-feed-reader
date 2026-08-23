import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { API_BASE_URL } from '../core/api';
import { ImportSectionComponent } from './import-section.component';
import { provideTranslocoTesting } from '../../testing/transloco-testing';
import { SubscriptionsStore } from '../reader/subscriptions.store';

describe('ImportSectionComponent', () => {
  const load = jest.fn();

  async function render() {
    await TestBed.configureTestingModule({
      imports: [ImportSectionComponent, provideTranslocoTesting()],
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: API_BASE_URL, useValue: 'https://api.test' },
        { provide: SubscriptionsStore, useValue: { load } },
      ],
    }).compileComponents();
    const fixture = TestBed.createComponent(ImportSectionComponent);
    fixture.detectChanges();
    return fixture.nativeElement as HTMLElement;
  }

  it('renders both the OPML section and the backup section', async () => {
    const el = await render();

    expect(el.querySelector('app-opml-section')).not.toBeNull();
    expect(el.querySelector('app-backup-section')).not.toBeNull();
  });

  // The whole point of #454: backup is not nested inside OPML any more, so the
  // stack's gap reaches it and no component carries a compensating margin.
  // Asserting they share a parent is what stops the nest from coming back.
  it('renders them as siblings in one stack', async () => {
    const el = await render();
    const opml = el.querySelector('app-opml-section')!;
    const backup = el.querySelector('app-backup-section')!;

    expect(backup.parentElement).toBe(opml.parentElement);
    expect(opml.parentElement?.tagName.toLowerCase()).toBe('app-settings-stack');
    expect(opml.querySelector('app-backup-section')).toBeNull();
  });
});
