export interface OpmlImportResult {
  imported: number;
  alreadySubscribed: number;
  invalid: number;
  skippedOverLimit: number;
}

/** One provider call logged during a for-you run: a scored batch or the
 *  final dedup pass. `verdict` is null while the call is still streaming. */
export interface DebugLogEntry {
  id: number;
  /** The run this call belongs to. The log can hold more than one run (a
   *  resumed run keeps appending), so the panel groups rows by it. */
  runId: number;
  phase: 'batch' | 'dedup';
  batchNumber: number | null;
  attempt: number;
  verdict: 'usable' | 'unusable' | 'transport-failed' | null;
  requestBytes: number;
  responseBytes: number;
  /** Everything the provider sent, reasoning and framing included. */
  wireBytes: number;
  streamingText: string | null;
  createdAt: string;
  /** Null while the call is still streaming; set the moment it settles. */
  finishedAt: string | null;
  /** The transport exception's message, set only on a `transport-failed`
   *  verdict -- null on every other row, including a completed run. */
  errorDetail: string | null;
  /** Why the provider stopped generating: `length` when `max_tokens` truncated
   *  the answer, `stop` on a natural end. Null until the provider stamps it. */
  finishReason: string | null;
}

/** One run the debug panel may switch to. The log keeps the last ten runs,
 *  and the panel reads one at a time -- shipping all ten on every two-second
 *  poll would cost ten times what the panel costs today. */
export interface DebugLogRunChoice {
  id: number;
  status: 'pending' | 'running' | 'completed' | 'failed';
  createdAt: string;
}

export interface DebugLogRunSummary {
  status: 'pending' | 'running' | 'completed' | 'failed';
  /** The run's own failure, distinct from a per-row `errorDetail` on
   *  `DebugLogEntry` -- null when the user has never run. */
  error: string | null;
  attempts: number;
  maxAttempts: number;
  transportFailures: number;
  maxTransportFailures: number;
  createdAt: string;
  completedAt: string | null;
}

/** What the debug panel's list route answers with. */
export interface DebugLogPayload {
  run: DebugLogRunSummary | null;
  runs: DebugLogRunChoice[];
  entries: DebugLogEntry[];
}

/** The full request/response pair for one logged provider call. */
export interface DebugLogDetail {
  id: number;
  phase: 'batch' | 'dedup';
  batchNumber: number | null;
  attempt: number;
  verdict: string | null;
  requestBody: string;
  responseText: string;
  wireBytes: number;
  /** Why the provider stopped generating: `length` when `max_tokens` truncated
   *  the answer, `stop` on a natural end. Null until the provider stamps it. */
  finishReason: string | null;
}

/** One finished (or in-flight) for-you run, as the history card shows it.
 *  Provider/model are what the run actually called, copied on start -- not the
 *  account's current (editable) config, which would otherwise rename past runs. */
export interface RunHistoryRow {
  id: number;
  status: 'pending' | 'running' | 'completed' | 'failed' | 'cancelled';
  /** Null on runs that predate the column, and on one that failed before it
   *  was ever stamped. */
  providerHost: string | null;
  model: string | null;
  createdAt: string;
  completedAt: string | null;
  /** Computed server-side -- the client never subtracts timestamps across
   *  machines. Null while the run has not finished. */
  durationSeconds: number | null;
  promptTokens: number;
  completionTokens: number;
  reasoningTokens: number;
  cachedTokens: number;
  /** What the run cost, in nano-credits (1 credit = 1e9). Null means no call
   *  of the run reported a price -- a local model, say -- which is a different
   *  statement from a cost of zero. */
  costNanoCredits: number | null;
}

/** One month of run history, as its section header shows it. `costNanoCredits`
 *  null means no run reported a price (same as a row's). Counts/totals are
 *  computed over the whole month, not the rows on screen, so a capped section
 *  never shows a wrong number. */
export interface RunHistoryMonth {
  month: string;
  runCount: number;
  costNanoCredits: number | null;
}

/** One page of one month's runs. `nextCursor` is the id to pass as `before`
 *  for the next page, or null when the month is exhausted. */
export interface RunHistoryMonthPage {
  month: string;
  runs: RunHistoryRow[];
  nextCursor: number | null;
}

/** What the history route answers with: every month the account has runs in,
 *  the newest month's first page so the card paints in one round trip, and the
 *  account's all-time total. `latest` is null when the account has never run. */
export interface RunHistoryOverview {
  totalCostNanoCredits: number | null;
  months: RunHistoryMonth[];
  latest: RunHistoryMonthPage | null;
}

/** One day of the reading-activity chart: the calendar day and how many
 *  articles the account opened on it. Quiet days are present with a count of
 *  zero, so the chart draws a continuous axis. */
export interface ReadingDay {
  date: string;
  count: number;
}

/** One feed in the "top feeds by read" ranking: the shared feed's id (the
 *  handle the reader scopes a feed view by) and how many of its articles the
 *  account has opened. The title is resolved on the client from the
 *  subscription list, so it stays the custom title the sidebar shows. */
export interface FeedReadCount {
  feedId: number;
  readCount: number;
}

/** The reading-activity payload: one entry per day of the window in order
 *  (oldest first), the window's total opens, and the account's most-read feeds
 *  all-time. */
export interface ReadingActivity {
  days: ReadingDay[];
  total: number;
  topFeedsByRead: FeedReadCount[];
}

export interface RestoreCounts {
  tags: number;
  savedSearches: number;
  feeds: number;
  subscriptions: number;
  entries: number;
  entryStates: number;
}

export interface RestorePreview {
  backup: {
    backupId: string;
    parts: number;
    createdAt: string;
    sourceUrl: string | null;
    sourceEmail: string | null;
  };
  toLoad: RestoreCounts;
  toDelete: {
    tags: number;
    subscriptions: number;
    entryStates: number;
    recommendationRuns: number;
  };
}

export interface RestoreResult {
  loaded: RestoreCounts;
}
