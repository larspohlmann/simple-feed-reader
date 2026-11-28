import { LabelCollection, LabelFilter } from '../labels/labels.models';

export type ExportFormatId = 'bookmarks' | 'google-reader';

export interface ExportFormat {
  id: ExportFormatId;
  nameKey: string;
  descriptionKey: string;
  icon: string;
  path: string;
  fallbackFilename: string;
}

export const EXPORT_FORMATS: readonly ExportFormat[] = [
  {
    id: 'bookmarks',
    nameKey: 'reader.export.bookmarksName',
    descriptionKey: 'reader.export.bookmarksDescription',
    icon: 'bookmark',
    path: '/api/exports/bookmarks',
    fallbackFilename: 'bookmarks.html',
  },
  {
    id: 'google-reader',
    nameKey: 'reader.export.googleReaderName',
    descriptionKey: 'reader.export.googleReaderDescription',
    icon: 'rss_feed',
    path: '/api/exports/google-reader',
    fallbackFilename: 'starred.json',
  },
];

/** What an export covers; `title` is the dialog heading's subject, already translated. */
export interface ExportTarget {
  collection: LabelCollection;
  label: LabelFilter | null;
  title: string;
}
