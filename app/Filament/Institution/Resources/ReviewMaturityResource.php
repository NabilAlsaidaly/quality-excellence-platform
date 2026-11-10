<?php

namespace App\Filament\Institution\Resources;

use App\Actions\Reviews\ReviewMaturityWorkspace;
use App\Filament\Institution\Resources\ReviewMaturityResource\Pages\ContentReviewWorkspace;
use App\Filament\Institution\Resources\ReviewMaturityResource\Pages\MaturityWorkspace;
use App\Filament\Institution\Resources\ReviewMaturityResource\Pages\WorkflowBank;
use App\Models\Approach;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

final class ReviewMaturityResource extends Resource
{
    protected static ?string $model = Approach::class;

    protected static bool $isDiscovered = false;

    protected static ?string $slug = 'workflows';

    public static function getModelLabel(): string
    {
        return 'منهجية';
    }

    public static function getPluralModelLabel(): string
    {
        return 'المراجعة والنضج';
    }

    public static function canAccess(): bool
    {
        return app(ReviewMaturityWorkspace::class)->canEnter();
    }

    public static function canGloballySearch(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return Approach::query()->whereRaw('1=0');
    }

    public static function getPages(): array
    {
        return ['index' => WorkflowBank::route('/'), 'content' => ContentReviewWorkspace::route('/{approach}'),
            'maturity' => MaturityWorkspace::route('/{approach}/maturity/{stage}')];
    }
}
