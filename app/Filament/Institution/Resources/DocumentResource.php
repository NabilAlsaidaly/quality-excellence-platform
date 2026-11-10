<?php

namespace App\Filament\Institution\Resources;

use App\Actions\Documents\DocumentWorkspace;
use App\Filament\Institution\Resources\DocumentResource\Pages\DocumentBank;
use App\Filament\Institution\Resources\DocumentResource\Pages\DocumentWorkspace as WorkspacePage;
use App\Models\Document;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static bool $isDiscovered = false;

    protected static ?string $recordRouteKeyName = 'public_id';

    public static function getModelLabel(): string
    {
        return 'وثيقة';
    }

    public static function getPluralModelLabel(): string
    {
        return 'بنك الوثائق';
    }

    public static function canAccess(): bool
    {
        return app(DocumentWorkspace::class)->can('list');
    }

    public static function getEloquentQuery(): Builder
    {
        return Document::query()->whereRaw('1 = 0');
    }

    public static function canGloballySearch(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => DocumentBank::route('/'), 'workspace' => WorkspacePage::route('/{document}')];
    }

    public static function metadataFields(): array
    {
        return [TextInput::make('title')->label('عنوان الوثيقة')->required()->maxLength(255),
            Textarea::make('description')->label('الوصف')->required()->maxLength(65535)->rows(6)];
    }
}
