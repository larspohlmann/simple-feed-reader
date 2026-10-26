import type { ChapterDto } from '../models';
import { scrubMarks } from './scrub-marks';

const chapters: ChapterDto[] = [
  { startSeconds: 0, title: 'Intro' },
  { startSeconds: 90, title: 'Main' },
  { startSeconds: 300, title: 'Outro' },
];

describe('scrubMarks', () => {
  it('places a mark at each chapter start as a share of the duration, none at 0', () => {
    expect(scrubMarks(chapters, 600)).toEqual([0.15, 0.5]);
  });

  it('draws no marks while the duration is unknown', () => {
    expect(scrubMarks(chapters, 0)).toEqual([]);
  });

  it('leaves out a chapter at or past the duration', () => {
    expect(scrubMarks([...chapters, { startSeconds: 600, title: 'Credits' }], 600)).toEqual([
      0.15, 0.5,
    ]);
    expect(scrubMarks(chapters, 300)).toEqual([0.3]);
  });

  it('draws nothing for a track without chapters', () => {
    expect(scrubMarks([], 600)).toEqual([]);
  });
});
