import { Signal, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { provideTranslocoTesting } from '../../../../testing/transloco-testing';
import { anEntry, anEntryComment } from '../../../../testing/entry-testing';
import { EntryDto } from '../../models';
import { CommentsService, CommentsState } from './comments.service';
import { EntryDiscussionLinkComponent } from './entry-discussion-link.component';

const OK_STATE: CommentsState = {
  status: 'ok',
  comments: [anEntryComment(), anEntryComment()],
  omitted: 1,
  fetchedAt: '2026-09-24T12:00:00Z',
  loadedAt: 0,
};

function linkText(over: Partial<EntryDto>, state: Signal<CommentsState>): string {
  TestBed.configureTestingModule({
    imports: [EntryDiscussionLinkComponent, provideTranslocoTesting()],
    providers: [{ provide: CommentsService, useValue: { state: () => state } }],
  });
  const fixture = TestBed.createComponent(EntryDiscussionLinkComponent);
  fixture.componentRef.setInput('entry', anEntry({ comments: 'auto', ...over }));
  fixture.detectChanges();
  return (fixture.nativeElement as HTMLElement).textContent ?? '';
}

describe('EntryDiscussionLinkComponent', () => {
  it('shows the stored comment count before the comments load', () => {
    expect(linkText({ commentCount: 38 }, signal<CommentsState>({ status: 'idle' }))).toContain(
      '(38)',
    );
  });

  it('prefers the loaded total once the comments are in', () => {
    expect(linkText({ commentCount: 38 }, signal<CommentsState>(OK_STATE))).toContain('(3)');
  });

  it('shows no number when nothing is stored and nothing is loaded', () => {
    expect(linkText({ commentCount: null }, signal<CommentsState>({ status: 'idle' }))).not.toMatch(
      /\(\d+\)/,
    );
  });
});
