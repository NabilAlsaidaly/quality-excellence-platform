<?php

namespace App\Filament\Institution\Pages;

use App\Filament\Institution\Approaches\ApproachAccess;
use App\Filament\Institution\Resources\ApproachResource;
use Filament\Actions\Action;
use Filament\Pages\Dashboard;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;

class InstitutionDashboard extends Dashboard
{
    protected static ?string $title = 'مساحة المؤسسة';

    public static function canAccess(): bool
    {
        return app(ApproachAccess::class)->can('list');
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        abort_unless(static::canAccess(), 403);

        return $schema->components([
            Section::make('بنك المنهجيات')->schema([
                Text::make('استعرض المنهجيات ونفّذ العمليات المسموح بها ضمن مؤسستك.'),
                Actions::make([Action::make('approaches')->label('فتح بنك المنهجيات')->url(ApproachResource::getUrl())]),
            ]),
            Section::make('الوظائف اللاحقة')->schema([Text::make('الوثائق والشواهد والمراجعات والنضج غير مفعّلة في هذه الشريحة.')]),
        ]);
    }
}
