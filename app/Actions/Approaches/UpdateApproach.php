<?php

namespace App\Actions\Approaches;

use App\Models\Approach;
use App\Models\ApproachChange;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** @internal Trusted mutation boundary, not an operational authorization grant. */
final class UpdateApproach
{
    public const FIELDS = ['name', 'description', 'purpose', 'scope', 'objectives', 'review_frequency'];

    public function __construct(private CreateApproach $boundary) {}

    public function execute(?User $actor, string $publicId, array $data, ?string $expectedToken = null): array
    {
        return $this->withinApproach($actor, $publicId, $expectedToken, function (Approach $approach, User $currentActor) use ($data): void {
            if (array_diff(array_keys($data), self::FIELDS) !== []) {
                throw ValidationException::withMessages(['approach' => 'تحتوي البيانات على حقول غير مسموح بها.']);
            }
            $rules = [];
            foreach (self::FIELDS as $field) {
                $rules[$field] = ['sometimes', in_array($field, ['objectives', 'review_frequency'], true) ? 'nullable' : 'required',
                    'string', 'max:'.($field === 'name' ? 255 : 65535)];
            }
            Validator::make($data, $rules, ['required' => 'هذا الحقل مطلوب.', 'string' => 'يجب إدخال نص صالح.', 'max' => 'تجاوز النص الحد التقني المسموح.'])->validate();
            foreach ($data as $field => $value) {
                if ($value !== null && (! mb_check_encoding($value, 'UTF-8')
                    || (! in_array($field, ['objectives', 'review_frequency'], true) && preg_match('/[^\p{Z}\s]/u', $value) !== 1))) {
                    throw ValidationException::withMessages([$field => 'يجب إدخال نص صالح غير فارغ.']);
                }
            }
            $before = self::snapshot($approach);
            if (array_replace($before, $data) === $before) {
                return;
            }
            DB::table('approaches')->where('organization_id', $approach->organization_id)->where('id', $approach->id)
                ->update($data + ['updated_at' => $approach->freshTimestampString()]);
            $approach->refresh();
            $this->record($approach, $currentActor, 'metadata_updated', $before, self::snapshot($approach));
        });
    }

    /** All callers remain internal and must supply a separately authorized actor. */
    public function withinApproach(?User $actor, string $publicId, ?string $expectedToken, Closure $operation): array
    {
        return $this->boundary->withinContext($actor, function (int $organizationId, User $currentActor) use ($publicId, $expectedToken, $operation): array {
            $approach = Str::isUlid($publicId) ? Approach::query()->where('organization_id', $organizationId)
                ->where('public_id', $publicId)->lockForUpdate()->first() : null;
            if ($approach === null) {
                throw (new ModelNotFoundException)->setModel(Approach::class);
            }
            $this->loadOwner($approach);
            if ($expectedToken === null || preg_match('/^[a-f0-9]{64}$/D', $expectedToken) !== 1) {
                throw ValidationException::withMessages(['edit_token' => 'يجب إعادة فتح النموذج قبل الحفظ.']);
            }
            if (! hash_equals($this->editToken($approach), $expectedToken)) {
                throw new ConflictHttpException('تغيرت المنهجية منذ فتح النموذج. أعد تحميلها وراجع التغييرات قبل الحفظ.');
            }
            $operation($approach, $currentActor);
            $this->loadOwner($approach);

            return ListApproaches::metadata($approach) + ['edit_token' => $this->editToken($approach)];
        });
    }

    /** Call under the organization's transaction lock; this is a current locking read. */
    public function editToken(Approach $approach): string
    {
        $last = ApproachChange::query()->where('organization_id', $approach->organization_id)
            ->where('approach_id', $approach->id)->orderByDesc('id')->lockForUpdate()->first();

        return hash('sha256', json_encode([$approach->organization_id, $approach->public_id,
            self::snapshot($approach), $approach->owningUnit->public_id, $last?->public_id], JSON_THROW_ON_ERROR));
    }

    public function record(Approach $approach, User $actor, string $event, array $before, array $after, ?int $previousOwner = null, ?int $newOwner = null): void
    {
        $change = new ApproachChange;
        $change->forceFill(['organization_id' => $approach->organization_id, 'approach_id' => $approach->id,
            'actor_id' => $actor->id, 'event' => $event, 'before_state' => $before, 'after_state' => $after,
            'previous_owner_id' => $previousOwner, 'new_owner_id' => $newOwner]);
        $change->save();
    }

    private static function snapshot(Approach $approach): array
    {
        return array_combine(self::FIELDS, array_map(fn ($field) => $approach->getAttribute($field), self::FIELDS));
    }

    private function loadOwner(Approach $approach): void
    {
        $approach->setRelation('owningUnit', OrganizationalUnit::query()->where('organization_id', $approach->organization_id)
            ->whereKey($approach->owning_unit_id)->lockForUpdate()->firstOrFail());
    }
}
