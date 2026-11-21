<?php

declare(strict_types=1);

namespace App\Service\Ingestion\Ingest\CrossPostKeyRule;

use App\Entity\CanonicalItem;
use App\Service\Ingestion\Parser\Model\ParsedEntryModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.cross_post_key_rule')]
interface CrossPostKeyRuleInterface
{
    public function findHash(CanonicalItem $item, ParsedEntryModel $parsed): ?string;
}
