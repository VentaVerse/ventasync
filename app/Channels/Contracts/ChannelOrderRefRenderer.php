<?php

namespace App\Channels\Contracts;

interface ChannelOrderRefRenderer
{
    public function renderOrderRef(string $orderChannel, string $channelOrderId): ?array;
}
