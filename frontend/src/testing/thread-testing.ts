import { ThreadPostDto } from '../app/reader/thread-post';

export function aThreadPost(overrides: Partial<ThreadPostDto> = {}): ThreadPostDto {
  return {
    url: 'https://blog.test/reply',
    publishedAt: '2026-10-17T10:00:00+00:00',
    html: '<p>A reply</p>',
    text: 'A reply',
    media: [],
    linkCard: null,
    ...overrides,
  };
}
