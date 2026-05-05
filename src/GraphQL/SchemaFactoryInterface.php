<?php

declare(strict_types=1);

namespace App\GraphQL;

use GraphQL\Type\Schema;

interface SchemaFactoryInterface
{
    public function create(): Schema;
}
