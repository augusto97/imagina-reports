<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\ConnectorServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    ConnectorServiceProvider::class,
    HorizonServiceProvider::class,
];
