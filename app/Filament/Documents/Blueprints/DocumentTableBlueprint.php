<?php

namespace App\Filament\Documents\Blueprints;

use Closure;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

/** Inert presentation. The caller must supply a freshly authorized read-service callback. */
final class DocumentTableBlueprint
{
    public static function configure(Table $table, ?Closure $authorizedSource = null): Table
    {
        return $table->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage) use ($authorizedSource): LengthAwarePaginator {
            $records = $authorizedSource === null ? new LengthAwarePaginator([], 0, $recordsPerPage, $page)
                : $authorizedSource($search ?? '', $sortColumn ?? 'created_at', $sortDirection ?? 'desc', $page, $recordsPerPage);

            return self::project($records, 'document_public_id', ['document_public_id', 'title', 'description', 'created_at', 'versions_count', 'latest_version_number']);
        })->columns([
            Split::make([
                TextColumn::make('title')->label('عنوان الوثيقة')->sortable()->wrap(),
                Stack::make([
                    TextColumn::make('versions_count')->label('عدد الإصدارات')->formatStateUsing(fn ($state) => 'عدد الإصدارات: '.$state),
                    TextColumn::make('latest_version_number')->label('آخر إصدار محفوظ')->formatStateUsing(fn ($state) => 'آخر إصدار محفوظ: '.$state)->placeholder('لا توجد إصدارات'),
                    TextColumn::make('created_at')->label('وقت الإنشاء')->sortable()->dateTime('Y-m-d H:i'),
                ]),
            ])->from('md'),
        ])->heading('الوثائق')->searchable()->searchPlaceholder('البحث بعنوان الوثيقة')
            ->defaultSort('created_at', 'desc')->paginated([10, 20])->defaultPaginationPageOption(20)
            ->recordActions([])->toolbarActions([])->headerActions([])->recordUrl(null)
            ->emptyStateHeading('لا توجد وثائق')->emptyStateDescription('لا توجد بيانات ضمن نطاق القراءة المسموح. رفع الملفات غير مفعّل.');
    }

    /** Defensive UI minimization, even if a future caller mistakenly supplies extra metadata. */
    public static function project(LengthAwarePaginator $records, string $key, array $fields): LengthAwarePaginator
    {
        $safe = clone $records;
        $safe->setCollection($records->getCollection()->mapWithKeys(function (array $record) use ($key, $fields): array {
            if (! isset($record[$key]) || ! Str::isUlid($record[$key])) {
                throw new RuntimeException('Presentation requires a public record identifier.');
            }
            $projection = Arr::only($record, $fields);
            foreach ($projection as $value) {
                if (! is_scalar($value) && $value !== null) {
                    throw new RuntimeException('Presentation accepts scalar read metadata only.');
                }
            }

            return [$record[$key] => $projection];
        }));

        return $safe->withPath('');
    }
}
