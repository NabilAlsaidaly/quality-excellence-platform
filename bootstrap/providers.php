<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\InstitutionPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    InstitutionPanelProvider::class,
];
