<?php

namespace App\Support;

use Illuminate\Support\Collection;

class Entry implements Renderable
{
    public string $ipAddress;
    /**
     * @var Collection | string[]
     */
    public Collection $domains;

    public function __construct(string $ipAddress, Collection $domains)
    {
        $this->ipAddress = $ipAddress;
        $this->domains = $domains;
    }

    public function render(): string
    {
        return $this->ipAddress . " " . $this->domains->join(' ');
    }
}