<?php

namespace App\Filament\Institution\Resources\DocumentResource\Pages;

use App\Actions\Documents\DocumentWorkspace;
use App\Filament\Institution\Resources\DocumentResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class DocumentBank extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = DocumentResource::class;

    public function getTitle(): string
    {
        return 'بنك الوثائق';
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([Text::make('مساحة اختبارية معزولة: بيانات الوثائق وإصدارات اصطناعية فقط. رفع الملفات وتنزيلها غير متاحين.')
            ->extraAttributes(['class' => 'min-w-0 whitespace-normal break-words']), EmbeddedTable::make()]);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage) {
            $records = app(DocumentWorkspace::class)->listing($search ?? '', $sortColumn ?? 'created_at', $sortDirection ?? 'desc', $page, $recordsPerPage);
            $records->setCollection($records->getCollection()->mapWithKeys(fn (array $row) => [$row['document_public_id'] => $row]));

            return $records;
        })->modelLabel('وثيقة')->pluralModelLabel('وثائق')->columns([Split::make([
            TextColumn::make('title')->label('عنوان الوثيقة')->wrap()->sortable(),
            TextColumn::make('created_at')->label('تاريخ الإنشاء')->dateTime('Y-m-d H:i')->sortable(),
        ])->from('md')])->searchable()->searchPlaceholder('ابحث في الوثائق المسندة إليك')->defaultSort('created_at', 'desc')
            ->paginated([10, 20])->defaultPaginationPageOption(20)->recordActions([])->toolbarActions([])
            ->recordUrl(fn (array $record) => $this->getResourceUrl('workspace', ['document' => $record['document_public_id']]))
            ->emptyStateHeading('لا توجد وثائق مسندة')->emptyStateDescription('تظهر هنا الوثائق ذات الإسناد الصريح الساري لحسابك.');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label('إنشاء وثيقة اختبارية')->modalSubmitActionLabel('إنشاء الوثيقة')
            ->modalDescription('تُحفظ بيانات الوثيقة مع محتوى اصطناعي آمن، دون استقبال ملفات من المستخدم.')
            ->visible(fn () => app(DocumentWorkspace::class)->can('create'))->schema(DocumentResource::metadataFields())
            ->action(function (array $data): void {
                app(DocumentWorkspace::class)->create($data);
                Notification::make()->title('حُفظت الوثيقة الاختبارية')->success()->send();
                $this->resetTable();
            }), Action::make('account')->label('حسابي')->url(fn () => route('institution.account', ['organization' => session('institution.organization')], false))];
    }

    #[Exposed]
    public function callMountedAction(array $arguments = []): mixed
    {
        abort_unless($this->getMountedAction()?->getName() === 'create' && app(DocumentWorkspace::class)->can('create'), 403);

        return parent::callMountedAction($arguments);
    }

    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        abort(403);
    }
}
