/** A client-side backup archive that failed to open, verify or parse. Lives
 *  apart from backup-archive.ts so backup-part-header.ts can throw it without
 *  the two files importing each other in a cycle. */
export class InvalidBackupArchiveError extends Error {}
