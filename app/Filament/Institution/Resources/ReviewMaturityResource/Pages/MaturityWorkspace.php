<?php

namespace App\Filament\Institution\Resources\ReviewMaturityResource\Pages;

use App\Actions\Maturity\ConfigureMaturityStage;
use App\Actions\Maturity\SaveStageSubmission;
use App\Actions\Reviews\DecideReview;
use App\Actions\Reviews\ReadReviewMaturityWorkspace;
use App\Authorization\ReviewMaturityAccess;
use App\Domain\Maturity\MaturityStage;
use App\Filament\Institution\Resources\ReviewMaturityResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

final class MaturityWorkspace extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ReviewMaturityResource::class;

    #[Locked]
    public string $approach;

    #[Locked]
    public string $stage;

    /** Request-local display DTO only. Authorization always runs in the application Action. */
    private ?array $workspaceSnapshot = null;

    public function mount(string $approach, string $stage): void
    {
        $this->approach = $approach;
        $this->stage = $stage;
        $this->current();
    }

    public function hydrate(): void
    {
        $this->workspaceSnapshot = null;
        $this->current();
    }

    public function getTitle(): string
    {
        return 'النضج — '.MaturityStage::from($this->current()['stage'])->label();
    }

    private function current(): array
    {
        $workspace = $this->workspaceSnapshot ??= app(ReadReviewMaturityWorkspace::class)->show($this->approach);
        $stage = collect($workspace['stages'])->first(fn ($row) => strcasecmp($row['public_id'], $this->stage) === 0);
        abort_unless($stage !== null, 404);

        return $stage + ['next_ordinal' => $workspace['next_ordinal']];
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([Text::make('دورة أولية اختبارية بخمس مراحل. التقدير تصنيف يدوي؛ لا توجد درجة رقمية أو نتيجة إجمالية. إعادة التقييم غير متاحة حتى حسم سياستها.'),
            Section::make('الأسئلة والشواهد والإجابات الحالية')->schema(function (): array {
                $current = $this->current();
                $answers = array_column($current['submission']['answers'] ?? [], 'answer', 'question_public_id');
                $components = [];
                foreach ($current['configuration']['questions'] ?? [] as $question) {
                    if (! $question['active']) {
                        continue;
                    }
                    $fields = [TextEntry::make('instructions'.$question['public_id'])->label('التعليمات')->state($question['instructions']),
                        TextEntry::make('answer'.$question['public_id'])->label($question['answer_required'] ? 'الإجابة — مطلوبة للاعتماد' : 'الإجابة — اختيارية')->state($answers[$question['public_id']] ?? 'لم تُحفظ إجابة بعد.')];
                    foreach ($question['expected_evidence'] as $expected) {
                        $fields[] = TextEntry::make('expected'.$expected['public_id'])->label($expected['required'] ? 'شاهد مطلوب للاعتماد' : 'شاهد اختياري')->state($expected['description']);
                        $references = array_filter($current['submission']['evidence'] ?? [], fn ($ref) => $ref['question_public_id'] === $question['public_id'] && $ref['expected_evidence_public_id'] === $expected['public_id']);
                        $fields[] = TextEntry::make('bound'.$expected['public_id'])->label('الإصدار المرتبط فعليًا')
                            ->state(implode("\n", array_column($references, 'display')) ?: 'لم يُربط شاهد بعد.')->extraAttributes(['class' => 'whitespace-normal break-words']);
                    }
                    $components[] = Section::make($question['text'])->schema($fields);
                }

                return $components ?: [Text::make('لم تُضبط أسئلة هذه المرحلة بعد. يحددها المراجع المخوّل في إعدادات المرحلة.')];
            }), Section::make('التقييم الأولي المحفوظ')->schema(function (): array {
                $assessment = $this->current()['assessment'];

                return $assessment ? [TextEntry::make('band')->label('التصنيف المختار يدويًا')->state($assessment['scale'][$assessment['selected_band_index']]['name']),
                    TextEntry::make('reason')->label('التعليل')->state($assessment['reason']), TextEntry::make('assessed_at')->label('تاريخ الحفظ')->state($assessment['created_at'])]
                    : [Text::make('لم يُعتمد تقييم هذه المرحلة بعد. لا تُفتح المرحلة التالية إلا بعد الاعتماد المستقل.')];
            }), Section::make('لقطة الأسئلة والشواهد عند الاعتماد')->schema(function (): array {
                $snapshot = $this->current()['assessment']['submission_snapshot'] ?? null;
                if ($snapshot === null) {
                    return [Text::make('تظهر اللقطة التاريخية بعد أول اعتماد مستقل.')];
                }
                $fields = [];
                $answers = array_column($snapshot['answers'], 'answer', 'question_public_id');
                foreach ($snapshot['configuration']['questions'] as $question) {
                    $fields[] = TextEntry::make('historical'.$question['public_id'])->label($question['text'])->state($answers[$question['public_id']] ?? 'بلا إجابة اختيارية');
                }
                foreach ($snapshot['evidence'] as $index => $reference) {
                    $fields[] = TextEntry::make('historical_evidence'.$index)->label('إصدار الشاهد التاريخي')->state($reference['display'])->extraAttributes(['class' => 'break-words']);
                }

                return $fields;
            }), Section::make('تاريخ المراجعة')->schema([EmbeddedTable::make()])]);
    }

    #[Unexposed]
    public function table(Table $table): Table
    {
        return $table->records(function (int $page) {
            $rows = app(ReadReviewMaturityWorkspace::class)->reviews($this->approach, $this->current()['id'], $page);
            $rows->setCollection($rows->getCollection()->mapWithKeys(fn ($row) => [$row['public_id'] => $row]));

            return $rows;
        })->columns([Split::make([TextColumn::make('review_number')->label('الدورة'), TextColumn::make('state')->label('الحالة')->badge()
            ->formatStateUsing(fn ($state) => ['pending' => 'بانتظار المراجعة', 'approved' => 'معتمد', 'changes_requested' => 'تصحيح مطلوب', 'superseded' => 'استُبدل بإصدار أحدث'][$state]),
            TextColumn::make('reason')->label('التعليل والملاحظات')->wrap(), TextColumn::make('decided_at')->label('تاريخ القرار')->dateTime()])->from('md')])
            ->paginated([20])->defaultPaginationPageOption(20)->recordActions([])->toolbarActions([])->emptyStateHeading('لا توجد مراجعات للمرحلة بعد');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('configure')->label('إعدادات المرحلة')->modalSubmitActionLabel('حفظ الإعدادات')->visible(fn () => app(ReviewMaturityAccess::class)->can('configure_stage', $this->approach, $this->current()['id']))
            ->modalDescription('نسخة إعدادات اختبارية خاصة بهذه المرحلة؛ لا تغير التقييمات أو الإجابات التاريخية.')
            ->fillForm(fn () => ($this->current()['configuration'] ?? ['questions' => [], 'scale' => []]) + ['configuration_token' => $this->current()['configuration_token']])
            ->schema($this->configurationFields())->action(function (array $data): void {
                app(ConfigureMaturityStage::class)->execute($this->approach, $this->stage, ['questions' => $data['questions'], 'scale' => $data['scale']], $data['configuration_token']);
                $this->workspaceSnapshot = null;
                Notification::make()->title('حُفظ إصدار إعدادات المرحلة')->success()->send();
            }), Action::make('answer')->label('تحرير إجابات المرحلة')->modalSubmitActionLabel('حفظ مسودة المرحلة')->visible(fn () => $this->current()['ordinal'] === $this->current()['next_ordinal']
                && $this->current()['configuration'] !== null && app(ReviewMaturityAccess::class)->can('answer_stage', $this->approach, $this->current()['id']))
            ->modalDescription('يمكن حفظ مسودة ناقصة؛ الاعتماد يتطلب الإجابات والشواهد الإلزامية ومراجعة مستقلة.')
            ->fillForm(function (): array {
                $current = $this->current();
                $evidence = [];
                foreach ($current['submission']['evidence'] ?? [] as $reference) {
                    if (! isset($reference['document_public_id'], $reference['version_public_id'])) {
                        continue;
                    }
                    $evidence[$reference['question_public_id']][$reference['expected_evidence_public_id']] = strtoupper($reference['document_public_id']).':'.strtoupper($reference['version_public_id']);
                }

                return ['answers' => array_column($current['submission']['answers'] ?? [], 'answer', 'question_public_id'), 'evidence' => $evidence,
                    'submission_token' => $current['submission_token'], 'configuration_token' => $current['configuration_token'], 'request_key' => (string) Str::ulid()];
            })->schema(fn () => $this->answerFields())->action(function (array $data): void {
                $references = [];
                foreach ($data['evidence'] ?? [] as $question => $expected) {
                    foreach ($expected as $id => $value) {
                        if (! is_string($value) || $value === '') {
                            continue;
                        }
                        $parts = explode(':', $value);
                        abort_unless(count($parts) === 2, 422);
                        $references[] = ['question_public_id' => $question, 'expected_evidence_public_id' => $id, 'document_public_id' => $parts[0], 'version_public_id' => $parts[1]];
                    }
                }
                $answers = array_map(fn ($answer) => $answer ?? '', $data['answers'] ?? []);
                app(SaveStageSubmission::class)->execute($this->approach, $this->stage, $answers, $references, $data['submission_token'], $data['configuration_token'], $data['request_key']);
                $this->workspaceSnapshot = null;
                Notification::make()->title('حُفظت المسودة وتزامنت المراجعة')->success()->send();
                $this->resetTable();
            }), Action::make('reviewStage')->label('مراجعة وتقييم المرحلة')->modalSubmitActionLabel('حفظ تقييم المرحلة')->visible(fn () => ($this->current()['review']['state'] ?? null) === 'pending'
                && app(ReviewMaturityAccess::class)->can('review_stage', $this->approach, $this->current()['id']))
            ->fillForm(fn () => ['review_id' => $this->current()['review']['public_id'], 'fingerprint' => $this->current()['review']['fingerprint'], 'request_key' => (string) Str::ulid()])
            ->schema(fn () => [Select::make('decision')->label('القرار')->options(['changes_requested' => 'طلب تصحيح', 'approved' => 'اعتماد المرحلة'])->required()->native(),
                Select::make('band_index')->label('التصنيف اليدوي عند الاعتماد')->options(array_column($this->current()['configuration']['scale'], 'name'))->native()->nullable(),
                Textarea::make('reason')->label('تعليل القرار وكفاية الشواهد')->required()->maxLength(65535), Textarea::make('notes')->label('ملاحظات إضافية')->maxLength(65535),
                Hidden::make('review_id'), Hidden::make('fingerprint'), Hidden::make('request_key')])
            ->action(function (array $data): void {
                app(DecideReview::class)->execute($data['review_id'], $data['fingerprint'], $data['decision'], $data['reason'], $data['request_key'],
                    isset($data['band_index']) && $data['band_index'] !== '' ? (int) $data['band_index'] : null, $data['notes'] ?? null);
                $this->workspaceSnapshot = null;
                Notification::make()->title('حُفظ قرار المرحلة والتقييم المستقل')->success()->send();
                $this->resetTable();
            }), Action::make('back')->label('المحتوى والمراحل')->url($this->getResourceUrl('content', ['approach' => $this->approach]))];
    }

    private function configurationFields(): array
    {
        return [Hidden::make('configuration_token'), Repeater::make('questions')->label('الأسئلة')->minItems(1)->maxItems(100)->defaultItems(0)->addActionLabel('إضافة سؤال')
            ->schema([Hidden::make('public_id')->default(fn () => (string) Str::ulid()), Textarea::make('text')->label('نص السؤال')->required()->maxLength(65535),
                Textarea::make('instructions')->label('التعليمات')->default('')->maxLength(65535), Toggle::make('active')->label('سؤال فعال')->default(true),
                Toggle::make('answer_required')->label('الإجابة مطلوبة للاعتماد')->default(true), Repeater::make('expected_evidence')->label('الشواهد المتوقعة')->addActionLabel('إضافة شاهد متوقع')->minItems(1)->maxItems(100)->defaultItems(1)
                    ->schema([Hidden::make('public_id')->default(fn () => (string) Str::ulid()), Textarea::make('description')->label('وصف الشاهد المتوقع')->required()->maxLength(65535),
                        Toggle::make('required')->label('شاهد مطلوب للاعتماد')->default(true)])]),
            Repeater::make('scale')->label('نسخة سلم التقييم الاختبارية')->minItems(1)->maxItems(100)->defaultItems(0)->addActionLabel('إضافة حالة تقييم')->schema([
                TextInput::make('name')->label('اسم الحالة')->required(), Textarea::make('description')->label('وصف الحالة')->default(''),
                TextInput::make('lower_bound')->label('الحد الأدنى')->numeric()->minValue(0)->maxValue(100)->required(),
                TextInput::make('upper_bound')->label('الحد الأعلى')->numeric()->minValue(0)->maxValue(100)->required()])];
    }

    private function answerFields(): array
    {
        $fields = [Hidden::make('submission_token'), Hidden::make('configuration_token'), Hidden::make('request_key')];
        foreach ($this->current()['configuration']['questions'] ?? [] as $question) {
            if (! $question['active']) {
                continue;
            }
            $fields[] = Textarea::make('answers.'.$question['public_id'])->label($question['text'])->helperText($question['instructions'])->maxLength(1000000)->rows(5)->default('');
            foreach ($question['expected_evidence'] as $expected) {
                $fields[] = Select::make('evidence.'.$question['public_id'].'.'.$expected['public_id'])->label($expected['description'])
                    ->helperText($expected['required'] ? 'مطلوب قبل الاعتماد؛ إصدارات اصطناعية مخولة فقط.' : 'اختياري؛ إصدارات اصطناعية مخولة فقط.')
                    ->options(fn () => app(ReadReviewMaturityWorkspace::class)->evidenceOptions($this->approach))->native()->nullable();
            }
        }

        return $fields;
    }

    #[Exposed]
    public function callMountedAction(array $arguments = []): mixed
    {
        $root = $this->getMountedAction(0);
        $mounted = $this->getMountedAction();
        $operation = match ($root?->getName()) {
            'configure' => 'configure_stage', 'answer' => 'answer_stage', 'reviewStage' => 'review_stage', default => null
        };
        if ($mounted !== $root) {
            $component = $mounted?->getSchemaComponent();
            abort_unless($root?->getName() === 'configure' && $component instanceof Repeater
                && preg_match('/\AmountedActions\.0\.data\.(questions|scale|questions\.[a-zA-Z0-9-]+\.expected_evidence)\z/', $component->getStatePath())
                && in_array($mounted->getName(), ['add', 'addBetween', 'delete', 'moveDown', 'moveUp', 'reorder', 'collapse', 'expand', 'collapseAll', 'expandAll'], true), 403);
        }
        abort_unless($operation !== null && app(ReviewMaturityAccess::class)->can($operation, $this->approach, $this->current()['id']), 403);

        return parent::callMountedAction($arguments);
    }

    public function _startUpload($name, $fileInfo, $isMultiple): void
    {
        abort(403);
    }
}
