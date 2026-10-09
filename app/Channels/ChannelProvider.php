<?php

namespace App\Channels;

interface ChannelProvider
{
    public function getId(): string;

    public function channelCards(): array;

    public function orderTabs(): array;
}
