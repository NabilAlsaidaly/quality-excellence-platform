<?php

namespace App\Filament\Institution\Resources\ReviewMaturityResource\Pages;

use App\Actions\Reviews\ReadReviewMaturityWorkspace;
use App\Filament\Institution\Resources\ReviewMaturityResource;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class WorkflowBank extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ReviewMaturityResource::class;

    public function getTitle(): string
    {
        return 'المراجعة والنضج';
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([Text::make('عرض محلي اختباري: مراجعة مستقلة ودورة نضج أولية. لا يمنح صلاحيات تشغيلية أو يتيح رفع الملفات وتنزيلها.')
            ->extraAttributes(['class' => 'whitespace-normal break-words']), EmbeddedTable::make()]);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage) {
            $records = app(ReadReviewMaturityWorkspace::class)->listing($search ?? '', $sortColumn ?? 'created_at', $sortDirection ?? 'desc', $page, $recordsPerPage);
            $records->setCollection($records->getCollection()->mapWithKeys(fn ($row) => [$row['approach_public_id'] => $row]));

            return $records;
        })->columns([TextColumn::make('name')->label('المنهجية')->wrap()->sortable(), TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime()->sortable()])
            ->searchable()->searchPlaceholder('ابحث في المنهجيات المسندة')->paginated([10, 20])->defaultPaginationPageOption(20)
            ->recordActions([])->toolbarActions([])->recordUrl(fn (array $record) => $this->getResourceUrl('content', ['approach' => $record['approach_public_id']]))
            ->emptyStateHeading('لا توجد منهجيات مسندة');
    }

    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        abort(403);
    }
}
