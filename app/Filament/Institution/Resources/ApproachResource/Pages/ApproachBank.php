<?php

namespace App\Filament\Institution\Resources\ApproachResource\Pages;

use App\Filament\Institution\Approaches\ApproachAccess;
use App\Filament\Institution\Resources\ApproachResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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

class ApproachBank extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ApproachResource::class;

    public function getTitle(): string
    {
        return __('approach_workspace.bank');
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([Text::make(__('approach_workspace.local_scope')), EmbeddedTable::make()]);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage) {
            $records = app(ApproachAccess::class)->listing($search ?? '', $sortColumn ?? 'created_at', $sortDirection ?? 'desc', $page, $recordsPerPage);
            $records->setCollection($records->getCollection()->mapWithKeys(fn (array $record) => [$record['public_id'] => [
                'public_id' => $record['public_id'], 'name' => $record['name'],
                'owning_unit' => $record['owning_unit'], 'created_at' => $record['created_at'],
                'can_view' => $record['can_view'],
            ]]));

            return $records;
        })->modelLabel('منهجية')->pluralModelLabel('منهجيات')->columns([
            Split::make([
                TextColumn::make('name')->label(__('approach_workspace.name'))->wrap()->sortable(),
                TextColumn::make('owning_unit.name')->label(__('approach_workspace.owner'))->wrap(),
                TextColumn::make('created_at')->label(__('approach_workspace.created_at'))->dateTime('Y-m-d H:i')->sortable(),
            ])->from('md'),
        ])->searchable()->searchPlaceholder(__('approach_workspace.search'))->defaultSort('created_at', 'desc')
            ->paginated([10, 20])->defaultPaginationPageOption(20)->recordActions([])->toolbarActions([])
            ->recordUrl(fn (array $record) => $record['can_view']
                ? $this->getResourceUrl('workspace', ['approach' => $record['public_id']]) : null)
            ->emptyStateHeading(__('approach_workspace.empty'));
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label(__('approach_workspace.create'))
            ->modalSubmitActionLabel(__('approach_workspace.create'))
            ->visible(fn () => app(ApproachAccess::class)->can('create'))
            ->schema([...ApproachResource::metadataFields(), Select::make('owning_unit_public_id')->label(__('approach_workspace.owner'))
                ->options(fn () => app(ApproachAccess::class)->searchOwners('create'))->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => app(ApproachAccess::class)->searchOwners('create', search: $search))
                ->getOptionLabelUsing(fn ($value) => is_string($value) ? (app(ApproachAccess::class)->searchOwners('create', selected: $value)[$value] ?? null) : null)])
            ->action(function (array $data): void {
                app(ApproachAccess::class)->create($data);
                Notification::make()->title(__('approach_workspace.saved'))->success()->send();
                $this->resetTable();
            })];
    }

    #[Exposed]
    public function callMountedAction(array $arguments = []): mixed
    {
        abort_unless($this->getMountedAction()?->getName() === 'create' && app(ApproachAccess::class)->can('create'), 403);

        return parent::callMountedAction($arguments);
    }

    // InteractsWithSchemas includes file-upload support. This Resource accepts none.
    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        abort(403);
    }
}
