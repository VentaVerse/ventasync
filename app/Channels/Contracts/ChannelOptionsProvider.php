<?php

namespace App\Channels\Contracts;

interface ChannelOptionsProvider extends ChannelLabelResolver
{
    public function availableSourceOptions(): array;
}
