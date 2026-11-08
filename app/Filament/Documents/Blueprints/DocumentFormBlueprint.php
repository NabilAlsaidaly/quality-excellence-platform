<?php

namespace App\Filament\Documents\Blueprints;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

final class DocumentFormBlueprint
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات الوثيقة — غير مفعّلة')->schema([
                TextInput::make('title')->label('عنوان الوثيقة')->maxLength(255)->disabled()->dehydrated(false),
                Textarea::make('description')->label('الوصف')->disabled()->dehydrated(false),
                Text::make(__('institution_documents.owner_blocked', [], 'ar')),
                Text::make(__('institution_documents.upload_blocked', [], 'ar')),
                Actions::make([
                    Action::make('uploadDisabled')->label('رفع الملفات غير مفعّل')->disabled()->authorize(false),
                ]),
            ]),
        ])->disabled();
    }
}
