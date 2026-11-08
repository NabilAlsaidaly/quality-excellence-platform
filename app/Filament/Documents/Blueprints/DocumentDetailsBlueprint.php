<?php

namespace App\Filament\Documents\Blueprints;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class DocumentDetailsBlueprint
{
    public static function configure(Schema $schema, array $metadata = []): Schema
    {
        return $schema->components([
            Section::make('معلومات الوثيقة')->schema([
                TextEntry::make('title')->label('عنوان الوثيقة')->state($metadata['title'] ?? 'لا توجد وثيقة محددة'),
                TextEntry::make('description')->label('الوصف')->state($metadata['description'] ?? 'لا توجد بيانات للعرض')->columnSpanFull(),
                TextEntry::make('document_public_id')->label('معرّف الوثيقة')->state($metadata['document_public_id'] ?? 'غير محدد'),
                TextEntry::make('latest_version_number')->label('آخر إصدار محفوظ')->state($metadata['latest_version_number'] ?? 'لا توجد إصدارات'),
            ])->columns(['default' => 1, 'md' => 2]),
        ]);
    }
}
