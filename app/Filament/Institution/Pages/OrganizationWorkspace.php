<?php

namespace App\Filament\Institution\Pages;

use App\Actions\OrganizationalUnits\AuthorizedUnitOperations;
use App\Actions\OrganizationalUnits\InvalidHierarchy;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Not registered in normal panels. Only the explicit isolated fixture mounts it. */
final class OrganizationWorkspace extends Page implements HasTable
{
    use InteractsWithTable;

    protected static bool $isDiscovered = false;

    protected static ?string $slug = 'organization';

    protected static ?string $navigationLabel = 'المؤسسة والوحدات';

    protected static ?string $title = 'المؤسسة والوحدات';

    private ?array $workspace = null;

    private static function withUnits(Closure $operation): mixed
    {
        $actor = Auth::guard('institution')->user();
        $organization = Auth::guard('institution')->getSession()->get('institution.organization');
        if (! $actor instanceof User || ! is_string($organization)) {
            throw new AuthorizationException;
        }

        return app(TenantContext::class)->run($actor, $organization,
            fn () => $operation(app(AuthorizedUnitOperations::class), $actor, $organization));
    }

    public static function canAccess(): bool
    {
        try {
            self::withUnits(fn (AuthorizedUnitOperations $units, User $actor, string $organization) => $units->readMetadata($actor, $organization));

            return true;
        } catch (AuthorizationException|HttpExceptionInterface) {
            return false;
        }
    }

    public function mount(): void
    {
        $this->current();
    }

    public function hydrate(): void
    {
        $this->workspace = null;
        $this->current();
    }

    private function current(): array
    {
        return $this->workspace ??= self::withUnits(fn (AuthorizedUnitOperations $units, User $actor, string $organization) => [
            'metadata' => $units->readMetadata($actor, $organization), 'tree' => $units->tree($actor, $organization),
        ]);
    }

    private function canManage(): bool
    {
        return self::withUnits(fn (AuthorizedUnitOperations $units, User $actor) => Gate::forUser($actor)
            ->allows('tenant-capability', ['organization.units.manage', Organization::findOrFail(app(TenantContext::class)->organizationId())]));
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([Text::make('عرض محلي اختباري بصلاحيات صريحة. لا يفعّل إدارة المؤسسة للمستخدمين التشغيليين.')
            ->extraAttributes(['class' => 'whitespace-normal break-words']),
            Section::make($this->current()['metadata']['name'])->schema([EmbeddedTable::make()])]);
    }

    private function parentField(): Select
    {
        return Select::make('parent_public_id')->label('الوحدة الأعلى')->placeholder('وحدة جذرية')->searchable()
            ->getSearchResultsUsing(fn (string $search) => collect($this->current()['tree']['nodes'])
                ->filter(fn ($node) => mb_stripos($node['name'], mb_substr($search, 0, 255)) !== false)->take(50)->pluck('name', 'public_id')->all())
            ->getOptionLabelUsing(fn ($value) => $this->current()['tree']['nodes'][$value]['name'] ?? null);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->records(function (?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int $recordsPerPage) {
            $tree = $this->current()['tree'];
            $rows = collect($tree['nodes'])->map(fn ($node) => $node + ['parent_name' => $tree['nodes'][$node['parent_public_id']]['name'] ?? 'وحدة جذرية']);
            if ($search !== null && $search !== '') {
                $rows = $rows->filter(fn ($node) => mb_stripos($node['name'], mb_substr($search, 0, 255)) !== false);
            }
            if (in_array($sortColumn, ['name', 'parent_name'], true)) {
                $rows = $sortDirection === 'desc' ? $rows->sortByDesc($sortColumn) : $rows->sortBy($sortColumn);
            }

            return new LengthAwarePaginator($rows->forPage($page, $recordsPerPage), $rows->count(), $recordsPerPage, $page);
        })->columns([Split::make([TextColumn::make('name')->label('الوحدة التنظيمية')->wrap()->sortable(),
            TextColumn::make('parent_name')->label('الوحدة الأعلى')->wrap()->sortable()])->from('md')])
            ->searchable()->searchPlaceholder('ابحث عن وحدة')->paginated([10, 25])->defaultPaginationPageOption(10)
            ->emptyStateHeading('لا توجد وحدات تنظيمية')->recordActions([ActionGroup::make([
                Action::make('renameUnit')->label('تغيير الاسم')->modalSubmitActionLabel('حفظ الاسم')->visible(fn () => $this->canManage())
                    ->fillForm(fn (array $record) => ['name' => $record['name']])
                    ->schema([TextInput::make('name')->label('اسم الوحدة')->required()->maxLength(255)])
                    ->action(function (array $record, array $data): void {
                        self::withUnits(fn (AuthorizedUnitOperations $units, User $actor, string $organization) => $units->rename($actor, $organization, $record['public_id'], $data['name']));
                        $this->saved();
                    }),
                Action::make('moveUnit')->label('نقل الوحدة')->modalSubmitActionLabel('حفظ النقل')->visible(fn () => $this->canManage())
                    ->fillForm(fn (array $record) => ['parent_public_id' => $record['parent_public_id']])->schema([$this->parentField()])
                    ->action(function (array $record, array $data): void {
                        try {
                            self::withUnits(fn (AuthorizedUnitOperations $units, User $actor, string $organization) => $units->move($actor, $organization, $record['public_id'], $data['parent_public_id'] ?? null));
                        } catch (InvalidHierarchy) {
                            throw ValidationException::withMessages([$this->getMountedActionSchema()->getStatePath().'.parent_public_id' => 'لا يمكن نقل الوحدة إلى نفسها أو إلى أحد فروعها.']);
                        }
                        $this->saved();
                    }),
            ])->label('إجراءات الوحدة')->button()->outlined()]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('createUnit')->label('إنشاء وحدة')->modalSubmitActionLabel('حفظ الوحدة')->visible(fn () => $this->canManage())
            ->schema([TextInput::make('name')->label('اسم الوحدة')->required()->maxLength(255), $this->parentField()])
            ->action(function (array $data): void {
                self::withUnits(fn (AuthorizedUnitOperations $units, User $actor, string $organization) => $units->create($actor, $organization, $data['name'], $data['parent_public_id'] ?? null));
                $this->saved();
            })];
    }

    private function saved(): void
    {
        $this->workspace = null;
        $this->resetTable();
        Notification::make()->title('حُفظ تغيير الوحدة')->success()->send();
    }

    #[Exposed]
    public function callMountedAction(array $arguments = []): mixed
    {
        abort_unless(in_array($this->getMountedAction()?->getName(), ['createUnit', 'renameUnit', 'moveUnit'], true) && $this->canManage(), 403);

        return parent::callMountedAction($arguments);
    }

    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        abort(403);
    }
}
