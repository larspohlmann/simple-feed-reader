<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** An ArrayAdapter that counts the items deleted from it: a dropped Bluesky session is one. */
final class CountingArrayAdapter extends ArrayAdapter
{
    public int $deletedItems = 0;

    public function deleteItem(mixed $key): bool
    {
        ++$this->deletedItems;

        return parent::deleteItem($key);
    }
}
