<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\JetstreamServiceProvider;
use App\Providers\StudioAiServiceProvider;
use App\Providers\StudioBackendServiceProvider;
use App\Providers\StudioBusinessServiceProvider;
use App\Providers\StudioIntegrationsServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    JetstreamServiceProvider::class,
    StudioBusinessServiceProvider::class,
    StudioIntegrationsServiceProvider::class,
    StudioBackendServiceProvider::class,
    StudioAiServiceProvider::class,
];
