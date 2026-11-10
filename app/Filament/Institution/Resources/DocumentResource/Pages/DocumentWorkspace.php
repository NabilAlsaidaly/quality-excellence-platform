<?php

namespace App\Filament\Institution\Resources\DocumentResource\Pages;

use App\Actions\Documents\DocumentWorkspace as Workspace;
use App\Filament\Institution\Resources\DocumentResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;

class DocumentWorkspace extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = DocumentResource::class;

    #[Locked]
    public string $document;

    public string $historyType = 'versions';

    public function mount(string $document): void
    {
        $this->document = $document;
        app(Workspace::class)->show($document);
    }

    public function hydrate(): void
    {
        app(Workspace::class)->show($this->document);
    }

    public function getTitle(): string
    {
        return 'مساحة الوثيقة';
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        $workspace = app(Workspace::class);
        $current = $workspace->show($this->document);
        $components = [Text::make('وثيقة اختبارية بمحتوى اصطناعي. الاعتماد والشواهد والرفع والتنزيل غير مفعّلة.')
            ->extraAttributes(['class' => 'min-w-0 whitespace-normal break-words']),
            Section::make('البيانات الحالية')->schema([
                TextEntry::make('title')->label('عنوان الوثيقة')->state($current['title']),
                TextEntry::make('description')->label('الوصف')->state($current['description'])->extraAttributes(['class' => 'break-words']),
            ])->columns(['default' => 1, 'md' => 2])];
        if ($workspace->can('history', $this->document)) {
            $components[] = Select::make('historyType')->label('نوع السجل')->options(['versions' => 'إصدارات المحتوى', 'metadata' => 'سجل البيانات الوصفية'])->live();
            // Keep the cached schema stable; Livewire updates visibility/state
            // after hydration when the user changes the history selector.
            $components[] = Section::make('البيانات الأصلية المحفوظة')->visible(fn () => $this->historyType === 'metadata')
                ->schema(function () use ($workspace): array {
                    $original = $workspace->originalMetadata($this->document);

                    return [TextEntry::make('original_title')->label('العنوان الأصلي')->state($original['title']),
                        TextEntry::make('original_description')->label('الوصف الأصلي')->state($original['description'])];
                });
            $components[] = EmbeddedTable::make();
        } else {
            $components[] = Text::make('لا يتوفر إسناد ساري لقراءة السجل.');
        }

        return $schema->components($components);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
            abort_unless(in_array($this->historyType, ['versions', 'metadata'], true), 403);
            if (! app(Workspace::class)->can('history', $this->document)) {
                return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
            }
            $records = app(Workspace::class)->history($this->document, $this->historyType, $page, $recordsPerPage);
            $records->setCollection($records->getCollection()->mapWithKeys(fn (array $row) => [$row['public_id'] => $row]));

            return $records;
        })->modelLabel('إصدار')->pluralModelLabel('إصدارات')->columns([Split::make([Stack::make([
            TextColumn::make('version_number')->label('الإصدار'),
            TextColumn::make('created_at')->label('وقت الحفظ')->dateTime('Y-m-d H:i'),
            TextColumn::make('actor_name')->label('صاحب التغيير')->wrap(),
        ]), Stack::make([
            TextColumn::make('title')->label('العنوان المحفوظ')->wrap()->placeholder('—'),
            TextColumn::make('description')->label('الوصف المحفوظ')->limit(240)->wrap()->placeholder('—'),
        ])])->from('md')])->paginated([10, 20])->defaultPaginationPageOption(20)->recordActions([])->toolbarActions([])->recordUrl(null)
            ->emptyStateHeading('لا توجد تغييرات محفوظة في هذا السجل');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('edit')->label('تعديل البيانات')->modalSubmitActionLabel('حفظ البيانات')
            ->visible(fn () => app(Workspace::class)->can('metadata', $this->document))
            ->fillForm(fn () => array_intersect_key(app(Workspace::class)->show($this->document), array_flip(['title', 'description', 'metadata_token'])))
            ->schema([...DocumentResource::metadataFields(), Hidden::make('metadata_token')->required()])
            ->action(function (array $data): void {
                $token = $data['metadata_token'];
                unset($data['metadata_token']);
                app(Workspace::class)->updateMetadata($this->document, $data, $token);
                $this->saved();
            }),
            Action::make('append')->label('إنشاء إصدار اختباري')->modalSubmitActionLabel('إنشاء الإصدار')->requiresConfirmation()
                ->modalDescription('ينشئ هذا الإجراء محتوى اختبارياً اصطناعياً جديداً، ولا يرفع أي ملف.')
                ->visible(fn () => app(Workspace::class)->can('append', $this->document))
                ->action(function (): void {
                    app(Workspace::class)->append($this->document);
                    $this->saved();
                }),
            Action::make('account')->label('حسابي')->url(fn () => route('institution.account', ['organization' => session('institution.organization')], false))];
    }

    private function saved(): void
    {
        $this->cacheSchema('content');
        $this->resetTable();
        Notification::make()->title('تم الحفظ مع الحفاظ على السجل السابق')->success()->send();
    }

    #[Exposed]
    public function callMountedAction(array $arguments = []): mixed
    {
        $operation = match ($this->getMountedAction()?->getName()) {
            'edit' => 'metadata', 'append' => 'append', default => null,
        };
        abort_unless($operation !== null && app(Workspace::class)->can($operation, $this->document), 403);

        return parent::callMountedAction($arguments);
    }

    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        abort(403);
    }
}
