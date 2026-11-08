<?php

namespace App\Filament\Institution\Resources;

use App\Filament\Institution\Approaches\ApproachAccess;
use App\Filament\Institution\Resources\ApproachResource\Pages\ApproachBank;
use App\Filament\Institution\Resources\ApproachResource\Pages\ApproachWorkspace;
use App\Models\Approach;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

/** Custom-data Resource: domain reads/writes only through the scoped adapter. */
class ApproachResource extends Resource
{
    protected static ?string $model = Approach::class;

    protected static bool $isDiscovered = false;

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function getModelLabel(): string
    {
        return 'منهجية';
    }

    public static function getPluralModelLabel(): string
    {
        return __('approach_workspace.bank');
    }

    public static function canAccess(): bool
    {
        return app(ApproachAccess::class)->can('list');
    }

    public static function getEloquentQuery(): Builder
    {
        // Never let a future generic CRUD/global-search path bypass Actions.
        return Approach::query()->whereRaw('1 = 0');
    }

    public static function canGloballySearch(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ApproachBank::route('/'), 'workspace' => ApproachWorkspace::route('/{approach}')];
    }

    public static function metadataFields(): array
    {
        $fields = [TextInput::make('name')->label(__('approach_workspace.name'))->required()->maxLength(255)];
        foreach (['description', 'purpose', 'scope', 'objectives', 'review_frequency'] as $field) {
            $fields[] = Textarea::make($field)->label(__('approach_workspace.'.$field))
                ->required(! in_array($field, ['objectives', 'review_frequency'], true))->maxLength(65535)->rows(3);
        }

        return $fields;
    }
}
