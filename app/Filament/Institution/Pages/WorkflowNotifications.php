<?php

namespace App\Filament\Institution\Pages;

use App\Actions\Notifications\ReviewMaturityNotifications;
use App\Actions\Reviews\ReviewMaturityWorkspace;
use App\Filament\Institution\Resources\ReviewMaturityResource;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Exposed;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

final class WorkflowNotifications extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'notifications';

    public static function getNavigationLabel(): string
    {
        return 'الإشعارات';
    }

    public function getTitle(): string
    {
        return 'إشعاراتي';
    }

    public static function canAccess(): bool
    {
        return app(ReviewMaturityWorkspace::class)->canEnter();
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->records(function (int $page) {
            $rows = app(ReviewMaturityNotifications::class)->inbox(app(ReviewMaturityWorkspace::class)->approachPublicId(), $page);
            $rows->setCollection($rows->getCollection()->mapWithKeys(fn ($row) => [$row->public_id => (array) $row]));

            return $rows;
        })->columns([Split::make([TextColumn::make('title')->label('الإشعار')->wrap(), TextColumn::make('reason')->label('التوضيح')->wrap(),
            TextColumn::make('read_at')->label('الحالة')->formatStateUsing(fn ($state) => $state ? 'مقروء' : 'غير مقروء')->placeholder('غير مقروء')])->from('md')])
            ->recordActions([Action::make('read')->label('تعليم كمقروء')->action(function (array $record): void {
                app(ReviewMaturityNotifications::class)->mark(app(ReviewMaturityWorkspace::class)->approachPublicId(), $record['public_id'], true);
                $this->resetTable();
            }), Action::make('unread')->label('تعليم كغير مقروء')->action(function (array $record): void {
                app(ReviewMaturityNotifications::class)->mark(app(ReviewMaturityWorkspace::class)->approachPublicId(), $record['public_id'], false);
                $this->resetTable();
            }), Action::make('open')->label('فتح مساحة العمل')->url(fn (array $record) => $record['stage_public_id'] === null
                ? ReviewMaturityResource::getUrl('content', ['approach' => app(ReviewMaturityWorkspace::class)->approachPublicId()])
                : ReviewMaturityResource::getUrl('maturity', ['approach' => app(ReviewMaturityWorkspace::class)->approachPublicId(), 'stage' => $record['stage_public_id']]))])
            ->toolbarActions([])->paginated([20])->defaultPaginationPageOption(20)->emptyStateHeading('لا توجد إشعارات مخولة');
    }

    #[Exposed]
    public function callMountedAction(array $arguments = []): mixed
    {
        abort_unless(in_array($this->getMountedAction()?->getName(), ['read', 'unread'], true) && self::canAccess(), 403);

        return parent::callMountedAction($arguments);
    }

    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        abort(403);
    }
}
