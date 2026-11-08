<?php

namespace App\Filament\Documents\Blueprints;

use Closure;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;

final class DocumentVersionsBlueprint
{
    public static function configure(Table $table, ?Closure $authorizedSource = null): Table
    {
        return $table->records(function (int $page, int $recordsPerPage) use ($authorizedSource): LengthAwarePaginator {
            $records = $authorizedSource === null ? new LengthAwarePaginator([], 0, $recordsPerPage, $page)
                : $authorizedSource($page, $recordsPerPage);

            return DocumentTableBlueprint::project($records, 'version_public_id', ['document_public_id', 'version_public_id', 'version_number', 'created_at']);
        })->columns([
            Split::make([
                TextColumn::make('version_number')->label('رقم الإصدار')->formatStateUsing(fn ($state) => 'إصدار رقم '.$state),
                TextColumn::make('created_at')->label('وقت الحفظ')->dateTime('Y-m-d H:i'),
                TextColumn::make('version_public_id')->label('معرّف الإصدار')->wrap(),
            ])->from('md'),
        ])->heading('سجل الإصدارات')->paginated([10, 20])->defaultPaginationPageOption(20)
            ->recordActions([])->toolbarActions([])->headerActions([])->recordUrl(null)
            ->emptyStateHeading('لا توجد إصدارات')->emptyStateDescription('لم يُحفظ إصدار ضمن نطاق القراءة المسموح.');
    }
}
