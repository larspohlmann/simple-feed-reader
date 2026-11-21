import { TestBed } from '@angular/core/testing';
import { TranslocoService } from '@jsverse/transloco';
import { provideTranslocoTesting } from '../../../testing/transloco-testing';
import {
  NotificationActorDto,
  NotificationGroupDto,
  NotificationKind,
} from './notification.models';
import { LinePart, notificationLine } from './notification-line';

function actor(displayName: string | null, handle = '@anna@social.example'): NotificationActorDto {
  return { handle, displayName, avatarUrl: null, profileUrl: 'https://social.example/@anna' };
}

function group(
  kind: NotificationKind,
  actors: NotificationActorDto[],
  actorCount = actors.length,
): NotificationGroupDto {
  return {
    id: 1,
    kind,
    account: '@lars.bsky.social',
    actors,
    actorCount,
    excerpt: null,
    answeredExcerpt: null,
    threadTitle: null,
    threadPlace: null,
    threadBy: null,
    onComment: false,
    linkUrl: 'https://social.example/p/1',
    entryId: null,
    latestAt: '2026-11-16T10:00:00Z',
    seen: true,
  };
}

function onComment(source: NotificationGroupDto): NotificationGroupDto {
  return { ...source, onComment: true };
}

function marked(parts: readonly LinePart[]): string {
  return parts.map((part) => (part.strong ? `**${part.text}**` : part.text)).join('');
}

describe('notificationLine', () => {
  let i18n: TranslocoService;
  const line = (source: NotificationGroupDto) =>
    notificationLine(source, (key, params) => i18n.translate(key, params));

  beforeEach(() => {
    TestBed.configureTestingModule({ imports: [provideTranslocoTesting()] });
    i18n = TestBed.inject(TranslocoService);
  });

  it('names one actor', () => {
    expect(marked(line(group('follow', [actor('LeVar Burton')])))).toBe(
      '**LeVar Burton** followed you',
    );
  });

  it('names two actors', () => {
    const source = group('repost', [actor('heise online'), actor('Laravel News')]);
    expect(marked(line(source))).toBe('**heise online** and **Laravel News** reposted your post');
  });

  it('names two and counts the rest', () => {
    const actors = [actor('Hank Green'), actor('Jay Graber'), actor('Third')];
    expect(marked(line(group('like', actors, 7)))).toBe(
      '**Hank Green**, **Jay Graber** and 5 others liked your post',
    );
    expect(marked(line(group('like', actors, 3)))).toBe(
      '**Hank Green**, **Jay Graber** and 1 other liked your post',
    );
  });

  it('names an actor by handle when the display name is missing', () => {
    expect(marked(line(group('mention', [actor(null)])))).toBe(
      '**@anna@social.example** mentioned you',
    );
  });

  it('says a poll ended with nobody named', () => {
    const parts = line(group('poll', [], 0));
    expect(marked(parts)).toBe('A poll you voted in has ended');
    expect(parts.some((part) => part.strong)).toBe(false);
  });

  it('keeps a name containing the marker text intact', () => {
    const parts = line(group('follow', [actor('a ** b')]));
    expect(parts.filter((part) => part.strong)).toEqual([{ text: 'a ** b', strong: true }]);
  });

  it.each([
    ['like', 'liked'],
    ['repost', 'reposted'],
    ['reply', 'replied to'],
    ['quote', 'quoted'],
  ] as const)('says a %s is about your comment or your post', (kind, verb) => {
    expect(marked(line(onComment(group(kind, [actor('Carol')]))))).toBe(
      `**Carol** ${verb} your comment`,
    );
    expect(marked(line(group(kind, [actor('Carol')])))).toBe(`**Carol** ${verb} your post`);
  });

  it('counts several actors on your comment', () => {
    const actors = [actor('Hank Green'), actor('Jay Graber')];
    expect(marked(line(onComment(group('like', actors))))).toBe(
      '**Hank Green** and **Jay Graber** liked your comment',
    );
  });
});
