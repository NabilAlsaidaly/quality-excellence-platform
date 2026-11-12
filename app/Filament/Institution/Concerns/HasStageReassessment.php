<?php

namespace App\Filament\Institution\Concerns;

use App\Actions\Maturity\DecideStageReassessment;
use App\Actions\Maturity\OpenStageReassessment;
use App\Actions\Maturity\ReadStageReassessmentHistory;
use App\Actions\Maturity\SaveStageReassessment;
use App\Authorization\ReviewMaturityAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Str;

trait HasStageReassessment
{
    private array|false|null $reassessmentSnapshot = null;

    private function reassessment(): ?array
    {
        if ($this->reassessmentSnapshot !== null) {
            return $this->reassessmentSnapshot ?: null;
        }
        $this->reassessmentSnapshot = false;
        if (ReviewMaturityAccess::hasReassessmentScope() && $this->current()['assessment'] !== null && $this->current()['next_ordinal'] === 6
            && app(ReviewMaturityAccess::class)->can('history_reassessment', $this->approach, $this->current()['id'])) {
            $this->reassessmentSnapshot = app(ReadStageReassessmentHistory::class)->execute($this->approach, $this->stage);
        }

        return $this->reassessmentSnapshot ?: null;
    }

    protected function reassessmentSection(): Section
    {
        return Section::make('إعادة التقييم التاريخية')->visible(fn () => $this->reassessment() !== null)->schema(function (): array {
            $data = $this->reassessment();
            if ($data === null) {
                return [];
            }
            $effective = $data['effective'];
            $active = $data['active_attempt'];
            $fields = [TextEntry::make('effective_band')->label('التصنيف الفعّال حاليًا')->state($effective['selected_band_name']),
                TextEntry::make('dependency_state')->label('حالة الاعتماد على المراحل السابقة')->state($effective['requires_revalidation'] ? 'تلزم إعادة التحقق' : 'سارية'),
                Text::make($effective['may_reassess'] ? 'إعادة التقييم تستخدم نسخة الأسئلة والسلم الأصليين؛ لا تغيّر التصنيف السابق قبل اعتماد مستقل.'
                    : 'أعمال هذه المرحلة محجوبة حتى إعادة اعتماد المراحل السابقة بالترتيب. التاريخ متاح للقراءة.')];
            if ($active !== null) {
                $fields[] = TextEntry::make('attempt_number')->label('محاولة إعادة التقييم المفتوحة')->state($active['attempt_number']);
                $fields[] = TextEntry::make('reassessment_reason')->label('ملاحظات المراجعة الحالية')->state($active['review']['reason'] ?? 'لم يصدر قرار بعد.');
                $answers = array_column($active['submission']['answers'] ?? [], 'answer', 'question_public_id');
                foreach ($active['configuration']['questions'] as $question) {
                    $fields[] = TextEntry::make('reassessment_answer_'.$question['public_id'])->label($question['text'])->state($answers[$question['public_id']] ?? 'لم تُحفظ إجابة لهذه المحاولة.');
                }
                foreach ($active['submission']['evidence'] ?? [] as $index => $reference) {
                    $fields[] = TextEntry::make('reassessment_evidence_'.$index)->label('إصدار الشاهد المختار لهذه المحاولة')->state($reference['display'])->extraAttributes(['class' => 'break-words']);
                }
            }

            return $fields;
        });
    }

    protected function reassessmentActions(): array
    {
        $may = fn (string $operation) => $this->reassessment() !== null && $this->reassessment()['effective']['may_reassess']
            && app(ReviewMaturityAccess::class)->can($operation, $this->approach, $this->current()['id']);

        return [Action::make('openReassessment')->label('طلب إعادة تقييم')->modalSubmitActionLabel('بدء المحاولة')->requiresConfirmation()
            ->visible(fn () => $may('open_reassessment') && $this->reassessment()['active_attempt'] === null)
            ->modalDescription('تُنشأ محاولة مستقلة؛ يبقى التقييم السابق فعّالًا حتى اعتماد المراجع المستقل.')
            ->fillForm(fn () => ['assessment_token' => $this->reassessment()['effective']['assessment_public_id'], 'request_key' => (string) Str::ulid()])
            ->schema([Hidden::make('assessment_token')->required(), Hidden::make('request_key')->required()])
            ->action(function (array $data): void {
                app(OpenStageReassessment::class)->execute($this->approach, $this->stage, $data['assessment_token'], $data['request_key']);
                $this->reassessmentSaved('فُتحت محاولة مستقلة؛ التقييم السابق محفوظ');
            }), Action::make('saveReassessment')->label('تحرير إعادة التقييم')->modalSubmitActionLabel('حفظ مسودة إعادة التقييم')
            ->visible(fn () => $may('save_reassessment') && $this->reassessment()['active_attempt'] !== null)
            ->modalDescription('الأسئلة والسلم من اللقطة الأصلية المعتمدة. اختر الشواهد صراحةً؛ كفايتها تخضع للمراجعة المستقلة.')
            ->fillForm(function (): array {
                $active = $this->reassessment()['active_attempt'];

                return ['attempt_id' => $active['public_id'], 'revision' => $active['revision'], 'request_key' => (string) Str::ulid(),
                    'answers' => array_column($active['submission']['answers'] ?? [], 'answer', 'question_public_id'), 'evidence' => []];
            })
            ->schema(fn () => [Hidden::make('attempt_id')->required(), Hidden::make('revision')->required(), Hidden::make('request_key')->required(),
                ...$this->questionAnswerFields($this->reassessment()['active_attempt']['configuration'])])
            ->action(function (array $data): void {
                app(SaveStageReassessment::class)->execute($data['attempt_id'], array_map(fn ($answer) => $answer ?? '', $data['answers'] ?? []),
                    $this->reassessmentReferences($data['evidence'] ?? []), (int) $data['revision'], $data['request_key']);
                $this->reassessmentSaved('حُفظ إصدار مستقل وأُرسل للمراجعة');
            }), Action::make('reviewReassessment')->label('مراجعة إعادة التقييم')->modalSubmitActionLabel('حفظ قرار إعادة التقييم')
            ->visible(fn () => $may('review_reassessment') && ($this->reassessment()['active_attempt']['review']['state'] ?? null) === 'pending')
            ->fillForm(function (): array {
                $active = $this->reassessment()['active_attempt'];

                return ['attempt_id' => $active['public_id'], 'revision' => $active['revision'], 'review_id' => $active['review']['public_id'],
                    'fingerprint' => $active['review']['fingerprint'], 'request_key' => (string) Str::ulid()];
            })
            ->schema(fn () => [Select::make('decision')->label('قرار إعادة التقييم')->options(['changes_requested' => 'طلب تصحيح', 'approved' => 'اعتماد إعادة التقييم'])->required()->native(),
                Select::make('band_index')->label('التصنيف اليدوي في السلم الأصلي')->options(array_column($this->reassessment()['active_attempt']['configuration']['scale'], 'name'))->native()->nullable(),
                Textarea::make('reason')->label('تعليل القرار وكفاية الشواهد')->required()->maxLength(65535),
                ...array_map(fn ($name) => Hidden::make($name)->required(), ['attempt_id', 'revision', 'review_id', 'fingerprint', 'request_key'])])
            ->action(function (array $data): void {
                app(DecideStageReassessment::class)->execute($data['attempt_id'], $data['review_id'], $data['fingerprint'], (int) $data['revision'],
                    $data['decision'], $data['reason'], $data['request_key'], isset($data['band_index']) && $data['band_index'] !== '' ? (int) $data['band_index'] : null);
                $this->reassessmentSaved('حُفظ القرار المستقل وأثره مع بقاء التاريخ');
            }), Action::make('reassessmentHistory')->label('سجل إعادة التقييم والمقارنة')->modalSubmitAction(false)->modalCancelActionLabel('إغلاق')
            ->visible(fn () => $this->reassessment() !== null)
            ->schema(function (): array {
                $data = $this->reassessment();
                $initial = json_decode($data['initial']->snapshot_json, true, 64, JSON_THROW_ON_ERROR);
                $rows = $data['recorded_history']->getCollection()->map(fn ($row) => ['number' => $row['attempt_number'],
                    'state' => $row['state'] === 'approved' ? 'معتمدة' : 'مفتوحة', 'date' => $row['created_at'], 'reason' => $row['reason'] ?? 'بانتظار القرار',
                    'band' => $row['snapshot'] ? $row['snapshot']['scale'][$row['snapshot']['selected_band_index']]['name'] : 'لا يوجد تصنيف جديد',
                    'answers' => implode("\n", array_column($row['snapshot']['answers'] ?? [], 'answer'))])->all();

                return [TextEntry::make('initial_band')->label('التصنيف الأولي المحفوظ')->state($initial['scale'][$initial['selected_band_index']]['name']),
                    TextEntry::make('current_band')->label('التصنيف الفعّال الحالي')->state($data['effective']['selected_band_name']),
                    TextEntry::make('initial_answers')->label('الإجابات الأصلية المحفوظة')->state(implode("\n", array_column($initial['answers'], 'answer'))),
                    RepeatableEntry::make('reassessment_history')->label('محاولات مستقلة دون استبدال الماضي')->state($rows)->schema([
                        TextEntry::make('number')->label('المحاولة'), TextEntry::make('state')->label('الحالة'), TextEntry::make('date')->label('تاريخ الإنشاء'),
                        TextEntry::make('band')->label('التصنيف'), TextEntry::make('reason')->label('تعليل القرار'), TextEntry::make('answers')->label('الإجابات المحفوظة'),
                    ])->columns(['default' => 1, 'md' => 2])];
            })];
    }

    private function reassessmentReferences(array $evidence): array
    {
        $references = [];
        foreach ($evidence as $question => $expected) {
            foreach ($expected as $id => $value) {
                if (! is_string($value) || $value === '') {
                    continue;
                }
                $parts = explode(':', $value);
                abort_unless(count($parts) === 2, 422);
                $references[] = ['question_public_id' => $question, 'expected_evidence_public_id' => $id, 'document_public_id' => $parts[0], 'version_public_id' => $parts[1]];
            }
        }

        return $references;
    }

    private function reassessmentSaved(string $message): void
    {
        $this->workspaceSnapshot = null;
        $this->reassessmentSnapshot = null;
        $this->cacheSchema('content');
        $this->resetTable();
        Notification::make()->title($message)->success()->send();
    }
}
