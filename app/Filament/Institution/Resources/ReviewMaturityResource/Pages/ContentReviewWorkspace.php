<?php

namespace App\Filament\Institution\Resources\ReviewMaturityResource\Pages;

use App\Actions\Reviews\DecideReview;
use App\Actions\Reviews\ReadReviewMaturityWorkspace;
use App\Actions\Reviews\SaveReviewableApproach;
use App\Authorization\ReviewMaturityAccess;
use App\Domain\Maturity\MaturityStage;
use App\Filament\Institution\Resources\ReviewMaturityResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

final class ContentReviewWorkspace extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ReviewMaturityResource::class;

    #[Locked]
    public string $approach;

    private ?array $workspaceSnapshot = null;

    public function getTitle(): string
    {
        return 'المحتوى والمراجعة';
    }

    public function mount(string $approach): void
    {
        $this->approach = $approach;
        app(ReadReviewMaturityWorkspace::class)->show($approach);
    }

    public function hydrate(): void
    {
        $this->workspaceSnapshot = null;
        $this->current();
    }

    private function current(): array
    {
        return $this->workspaceSnapshot ??= app(ReadReviewMaturityWorkspace::class)->show($this->approach);
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        $current = $this->current();

        return $schema->components([Text::make('حفظ التغيير يفتح مراجعة تلقائيًا. لا يستطيع المؤلف اعتماد مساهمته. هذه صلاحيات اختبارية محلية فقط.'),
            Section::make($current['name'])->schema([TextEntry::make('body')->label('المحتوى الحالي')->state(fn () => $this->current()['body'])->extraAttributes(['class' => 'whitespace-pre-wrap break-words'])]),
            Section::make('سجل المراجعة والقرارات')->schema([EmbeddedTable::make()])]);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->records(function (int $page) {
            $rows = app(ReadReviewMaturityWorkspace::class)->reviews($this->approach, page: $page);
            $rows->setCollection($rows->getCollection()->mapWithKeys(fn ($row) => [$row['public_id'] => $row]));

            return $rows;
        })->columns([Split::make([TextColumn::make('review_number')->label('الدورة'), TextColumn::make('state')->label('الحالة')->badge()
            ->formatStateUsing(fn ($state) => ['pending' => 'بانتظار المراجعة', 'approved' => 'معتمد', 'changes_requested' => 'تصحيح مطلوب', 'superseded' => 'استُبدل بإصدار أحدث'][$state]),
            TextColumn::make('reason')->label('الملاحظات')->wrap(), TextColumn::make('decided_at')->label('تاريخ القرار')->dateTime()])->from('md')])
            ->paginated([20])->defaultPaginationPageOption(20)->recordActions([])->toolbarActions([])->emptyStateHeading('احفظ المحتوى لبدء المراجعة');
    }

    protected function getHeaderActions(): array
    {
        $access = app(ReviewMaturityAccess::class);
        $actions = [Action::make('editContent')->label('تحرير المحتوى')->modalSubmitActionLabel('حفظ المحتوى')->visible(fn () => $access->can('edit_content', $this->approach))
            ->fillForm(fn () => $this->current() + ['request_key' => (string) Str::ulid()])
            ->schema([Textarea::make('body')->label('محتوى المنهجية')->required()->maxLength(1000000)->rows(12), Hidden::make('draft_token'), Hidden::make('request_key')])
            ->action(function (array $data): void {
                app(SaveReviewableApproach::class)->execute($this->approach, $data['body'], $data['draft_token'], $data['request_key']);
                $this->workspaceSnapshot = null;
                Notification::make()->title('حُفظ المحتوى وتزامنت مراجعته')->success()->send();
                $this->resetTable();
            }), Action::make('review')->label('قرار المراجعة')->modalSubmitActionLabel('حفظ القرار')->visible(fn () => $access->can('review_content', $this->approach)
                && ($this->current()['review']['state'] ?? null) === 'pending')
            ->fillForm(fn () => ['review_id' => $this->current()['review']['public_id'],
                'fingerprint' => $this->current()['review']['fingerprint'], 'request_key' => (string) Str::ulid()])
            ->schema([Select::make('decision')->label('القرار')->options(['changes_requested' => 'طلب تصحيح', 'approved' => 'اعتماد الإصدار'])->required()->native(),
                Textarea::make('reason')->label('ملاحظات المراجعة')->required(fn (Get $get) => $get('decision') === 'changes_requested')->maxLength(65535)->rows(4), Hidden::make('review_id'), Hidden::make('fingerprint'), Hidden::make('request_key')])
            ->action(function (array $data): void {
                app(DecideReview::class)->execute($data['review_id'], $data['fingerprint'], $data['decision'], $data['reason'] ?? null, $data['request_key']);
                $this->workspaceSnapshot = null;
                Notification::make()->title('حُفظ قرار المراجعة')->success()->send();
                $this->resetTable();
            })];
        foreach ($this->current()['stages'] as $stage) {
            $actions[] = Action::make('stage'.$stage['ordinal'])->label(MaturityStage::from($stage['stage'])->label())->color('gray')
                ->url($this->getResourceUrl('maturity', ['approach' => $this->approach, 'stage' => $stage['public_id']]));
        }

        return $actions;
    }

    #[Exposed]
    public function callMountedAction(array $arguments = []): mixed
    {
        $operation = match ($this->getMountedAction()?->getName()) {
            'editContent' => 'edit_content', 'review' => 'review_content', default => null
        };
        abort_unless($operation !== null && app(ReviewMaturityAccess::class)->can($operation, $this->approach), 403);

        return parent::callMountedAction($arguments);
    }

    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        abort(403);
    }
}
