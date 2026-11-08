<?php

namespace App\Filament\Institution\Resources\ApproachResource\Pages;

use App\Filament\Institution\Approaches\ApproachAccess;
use App\Filament\Institution\Resources\ApproachResource;
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
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;

class ApproachWorkspace extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ApproachResource::class;

    #[Locked]
    public string $approach;

    public string $historyType = 'draft';

    public function mount(string $approach): void
    {
        $this->approach = $approach;
        app(ApproachAccess::class)->show($this->approach);
    }

    public function hydrate(): void
    {
        // Resource-level list access is insufficient for a mounted detail/update.
        app(ApproachAccess::class)->show($this->approach);
    }

    public function getTitle(): string
    {
        return __('approach_workspace.workspace');
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        $metadata = app(ApproachAccess::class)->show($this->approach);
        $entries = [];
        foreach (['name', 'description', 'purpose', 'scope', 'objectives', 'review_frequency'] as $field) {
            $entries[] = TextEntry::make($field)->label(__('approach_workspace.'.$field))->state($metadata[$field])->placeholder('—');
        }
        $entries[] = TextEntry::make('owner')->label(__('approach_workspace.owner'))->state($metadata['owning_unit']['name']);

        return $schema->components([
            Text::make(__('approach_workspace.gated')),
            Section::make(__('approach_workspace.workspace'))->schema($entries)->columns(['default' => 1, 'md' => 2]),
            Section::make('النسخة المنشورة')->schema([Text::make(__('approach_workspace.publication_unavailable'))]),
            Select::make('historyType')->label('نوع السجل')->options(['draft' => __('approach_workspace.drafts'), 'audit' => __('approach_workspace.audit')])->live(),
            EmbeddedTable::make(),
        ]);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->records(function (int $page, int $recordsPerPage): LengthAwarePaginator {
            abort_unless(in_array($this->historyType, ['draft', 'audit'], true), 403);
            $ability = $this->historyType === 'draft' ? 'viewDraftHistory' : 'viewAuditHistory';
            if (! app(ApproachAccess::class)->can($ability, $this->approach)) {
                return new LengthAwarePaginator([], 0, $recordsPerPage, $page);
            }
            $records = app(ApproachAccess::class)->history($this->approach, $this->historyType, $page, $recordsPerPage);
            $records->setCollection($records->getCollection()->mapWithKeys(fn (array $record) => [$record['public_id'] => $record]));

            return $records;
        })->modelLabel('تغيير')->pluralModelLabel('تغييرات')->columns([Split::make([Stack::make([
            TextColumn::make('version_number')->label(__('approach_workspace.version'))->placeholder('—'),
            TextColumn::make('body')->label(__('approach_workspace.body'))->wrap()->placeholder('—'),
            TextColumn::make('created_at')->label(__('approach_workspace.created_at'))->dateTime('Y-m-d H:i'),
        ]), Stack::make([
            TextColumn::make('event')->label(__('approach_workspace.event'))->formatStateUsing(fn (?string $state) => $state ? __('approach_workspace.'.$state) : '—'),
            TextColumn::make('before_state')->label(__('approach_workspace.before'))->formatStateUsing(fn ($state) => $this->formatHistoryState($state))->wrap(),
            TextColumn::make('after_state')->label(__('approach_workspace.after'))->formatStateUsing(fn ($state) => $this->formatHistoryState($state))->wrap(),
        ])])->from('md')])->paginated([10, 20])->defaultPaginationPageOption(20)->recordActions([])->toolbarActions([])->recordUrl(null)
            ->emptyStateHeading(__('approach_workspace.empty_history'))->emptyStateDescription(__('approach_workspace.history_denied'));
    }

    private function formatHistoryState(mixed $state): string
    {
        if (! is_array($state)) {
            return is_string($state) ? $state : '—';
        }

        return implode('؛ ', array_map(fn ($key, $value) => ($key === 'public_id' ? 'المعرّف' : __('approach_workspace.'.$key)).': '.($value ?? '—'), array_keys($state), array_values($state)));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')->label(__('approach_workspace.edit'))
                ->visible(fn () => app(ApproachAccess::class)->can('updateMetadata', $this->approach))
                ->fillForm(fn () => app(ApproachAccess::class)->show($this->approach))
                ->schema([...ApproachResource::metadataFields(), Hidden::make('edit_token')->required()])
                ->action(function (array $data): void {
                    $token = $data['edit_token'];
                    unset($data['edit_token']);
                    app(ApproachAccess::class)->update($this->approach, $data, $token);
                    $this->saved();
                }),
            Action::make('transfer')->label(__('approach_workspace.transfer'))->requiresConfirmation()
                ->modalDescription(__('approach_workspace.confirm_transfer'))
                ->visible(fn () => app(ApproachAccess::class)->can('changeOwner', $this->approach))
                ->fillForm(fn () => ['edit_token' => app(ApproachAccess::class)->show($this->approach)['edit_token']])
                ->schema([Select::make('owning_unit_public_id')->label(__('approach_workspace.owner'))->required()->searchable()
                    ->options(fn () => app(ApproachAccess::class)->ownerOptions('changeOwner', $this->approach)), Hidden::make('edit_token')->required()])
                ->action(function (array $data): void {
                    app(ApproachAccess::class)->changeOwner($this->approach, $data['owning_unit_public_id'], $data['edit_token']);
                    $this->saved();
                }),
            Action::make('draft')->label(__('approach_workspace.save_draft'))
                ->visible(fn () => app(ApproachAccess::class)->can('editDraft', $this->approach))
                ->fillForm(fn () => ['draft_token' => app(ApproachAccess::class)->draftToken($this->approach)])
                ->schema([Textarea::make('body')->label(__('approach_workspace.body'))->required()->maxLength(1000000)->rows(10), Hidden::make('draft_token')->required()])
                ->action(function (array $data): void {
                    app(ApproachAccess::class)->saveDraft($this->approach, $data['body'], $data['draft_token']);
                    $this->saved();
                }),
        ];
    }

    private function saved(): void
    {
        Notification::make()->title(__('approach_workspace.saved'))->success()->send();
        $this->resetTable();
    }

    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        abort(403);
    }
}
