<?php

namespace App\Channels\Listings\Templates;

use Illuminate\Database\Eloquent\Model;

interface TemplateFields
{
    public function fields(): array;

    public function templateValues(Model $template): array;

    public function listing(int $productId): Model;

    public function attributes(Model $listing): array;

    public function attributeLabels(Model $template): array;

    public function save(Model $listing, array $attributes): void;
}
