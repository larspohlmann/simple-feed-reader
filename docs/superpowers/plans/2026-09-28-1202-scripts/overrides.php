<?php

declare(strict_types=1);

// Judgment calls on top of ServiceRoleRule's homes, read by derive-moves.php: class => [check, home].
// A null home keeps the class where it is. Each entry names the PR check that moves it; see the plan's "Overrides".

return [
    // Per-call objects the rule reads as data: each holds one call's input and is used within that call.
    'App\\Service\\Fetch\\PageUrls' => ['passHome', 'App\\Service\\Fetch\\Pass\\PageUrls'],
    'App\\Service\\Scraper\\CardFields' => ['passHome', 'App\\Service\\Scraper\\Pass\\CardFields'],
    'App\\Service\\Reader\\LeadingFurniture' => ['passHome', 'App\\Service\\Reader\\Pass\\LeadingFurniture'],
    'App\\Service\\Reader\\Media\\Sibling\\SiblingSearch' => [
        'passHome',
        'App\\Service\\Reader\\Media\\Sibling\\Pass\\SiblingSearch',
    ],
    'App\\Service\\Parser\\FeedMediaNode' => ['passHome', 'App\\Service\\Parser\\Pass\\FeedMediaNode'],
    'App\\Service\\ReaderAudit\\AuditReportHtml' => ['passHome', 'App\\Service\\ReaderAudit\\Pass\\AuditReportHtml'],
    'App\\Service\\ReaderAudit\\AuditFindingsFile' => [
        'passHome',
        'App\\Service\\ReaderAudit\\Pass\\AuditFindingsFile',
    ],
    'App\\Service\\Backup\\RestoreDestination' => ['passHome', 'App\\Service\\Backup\\Pass\\RestoreDestination'],
    // The Source/ folder dissolves into MediaCandidateSource/; its one non-source class joins Media's models.
    'App\\Service\\Reader\\Media\\Source\\ScannedPage' => [
        'modelHome',
        'App\\Service\\Reader\\Media\\Model\\ScannedPageModel',
    ],
    // The issue renames Ingest/Platform/ to PlatformEntryRule/; the rule collection it also held moves to Ingest/.
    'App\\Service\\Ingest\\Platform\\PlatformEntryRule' => [
        'interfaceName',
        'App\\Service\\Ingest\\PlatformEntryRule\\PlatformEntryRuleInterface',
    ],
    'App\\Service\\Ingest\\Platform\\RedditEntryRule' => [
        'interfaceFolder',
        'App\\Service\\Ingest\\PlatformEntryRule\\RedditEntryRule',
    ],
    'App\\Service\\Ingest\\Platform\\PlatformEntryRules' => [
        'interfaceFolder',
        'App\\Service\\Ingest\\PlatformEntryRules',
    ],
    // The test trait the sources' tests share follows them out of Source/.
    'App\\Tests\\Service\\Reader\\Media\\Source\\FindsMediaInRawPage' => [
        'interfaceFolder',
        'App\\Tests\\Service\\Reader\\Media\\MediaCandidateSource\\FindsMediaInRawPage',
    ],
    // Factories under another name (#1202): each builds and returns an object, so it takes the suffix.
    'App\\Service\\Mail\\Digest\\DigestMailBuilder' => [
        'factoryName',
        'App\\Service\\Mail\\Digest\\Factory\\DigestMailFactory',
    ],
    'App\\Service\\Mail\\Digest\\DigestPageBuilder' => [
        'factoryName',
        'App\\Service\\Mail\\Digest\\Factory\\DigestPageFactory',
    ],
    'App\\Service\\Mail\\Transport\\EsmtpTransportBuilder' => [
        'factoryName',
        'App\\Service\\Mail\\Transport\\Factory\\EsmtpTransportFactory',
    ],
    // Named by the issue although App\Entity is outside the rule: renamed in place.
    'App\\Entity\\Positioned' => ['interfaceName', 'App\\Entity\\PositionedInterface'],
];
