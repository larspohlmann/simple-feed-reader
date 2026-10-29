import { carriesUrl, droppedUrl } from './dropped-url';
import { fakeDataTransfer } from '../../../../testing/fake-data-transfer';

describe('carriesUrl', () => {
  it('accepts a drag that carries a uri-list', () => {
    expect(carriesUrl(fakeDataTransfer({ 'text/uri-list': '' }))).toBe(true);
  });

  it('rejects plain text, files and a missing transfer', () => {
    expect(carriesUrl(fakeDataTransfer({ 'text/plain': 'https://example.com' }))).toBe(false);
    expect(carriesUrl(fakeDataTransfer({ Files: '' }))).toBe(false);
    expect(carriesUrl(null)).toBe(false);
  });
});

describe('droppedUrl', () => {
  it('takes the first address of the uri-list, skipping comments', () => {
    const transfer = fakeDataTransfer({
      'text/uri-list': '# Example\r\nhttps://example.com/blog\r\nhttps://other.example/\r\n',
    });

    expect(droppedUrl(transfer)).toBe('https://example.com/blog');
  });

  it('skips addresses that are not on the web', () => {
    const transfer = fakeDataTransfer({
      'text/uri-list': 'mailto:someone@example.com\nhttp://example.com/feed',
    });

    expect(droppedUrl(transfer)).toBe('http://example.com/feed');
  });

  it('falls back to plain text that is itself an address', () => {
    const transfer = fakeDataTransfer({
      'text/uri-list': '',
      'text/plain': '  https://example.com/  ',
    });

    expect(droppedUrl(transfer)).toBe('https://example.com/');
  });

  it('finds nothing without a transfer', () => {
    expect(droppedUrl(null)).toBeNull();
  });

  it('finds nothing in text that is not an address', () => {
    const transfer = fakeDataTransfer({
      'text/uri-list': 'javascript:alert(1)',
      'text/plain': 'just some words',
    });

    expect(droppedUrl(transfer)).toBeNull();
  });
});
