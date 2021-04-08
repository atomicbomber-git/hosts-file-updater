<?php

namespace App\Support;

use Illuminate\Support\Collection;

class EntryGroup implements Renderable
{
    /**
     * @var Collection | \App\Support\Entry[]
     */
    public Collection $entries;

    public function __construct(Collection $entries)
    {
        $this->entries = $entries;
    }

    public function render(): string
    {
        return $this->entries->map(fn(\App\Support\Entry $entry) => $entry->render())->join("\n");
    }
}