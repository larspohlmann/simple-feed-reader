<?php

declare(strict_types=1);

namespace App\Service\Reader\Model;

/** Why an extraction failed. The values are wire vocabulary the reader client switches on. */
enum ExtractionFailure: string
{
    case NoUrl = 'no_url';
    /** The page could not be retrieved: network, SSRF-blocked, oversized. */
    case Fetch = 'fetch';
    /** The page could not be parsed, or readability found no article in it. */
    case Unextractable = 'unextractable';
    /** The extraction held too little to show, before or after sanitising. */
    case Empty = 'empty';
    /** The extraction did not reflect the article the feed carries. */
    case Mismatch = 'mismatch';
}
