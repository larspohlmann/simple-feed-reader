<?php

declare(strict_types=1);

namespace App\Service\Foundation\CanonicalItem\CanonicalKeyRule;

use App\Entity\Entry;
use App\Service\Foundation\CanonicalItem\Model\CanonicalKeyModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.canonical_key_rule')]
interface CanonicalKeyRuleInterface
{
    public function findKey(Entry $entry): ?CanonicalKeyModel;
}
