import { signedUrlOf } from './link-card';
import { aLinkCard, A_READER_TOKEN } from '../../../testing/entry-testing';

describe('signedUrlOf', () => {
  it('reads a card as its url and token', () => {
    expect(signedUrlOf(aLinkCard({ url: 'https://x.test/1' }))).toEqual({
      url: 'https://x.test/1',
      token: A_READER_TOKEN,
    });
  });
});
