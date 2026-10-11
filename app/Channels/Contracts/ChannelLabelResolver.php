<?php

namespace App\Channels\Contracts;

interface ChannelLabelResolver
{
    public function resolveSourceLabel(string $source): ?string;
}
