<?php

namespace App\Actions\Approaches;

use App\Authorization\TenantAuthorization;
use App\Authorization\TenantContext;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** @internal Structural domain operations only. NOT an end-user permission grant. */
final class CreateApproach
{
    public function __construct(private TenantContext $context) {}

    public function execute(?User $actor, array $data): Approach
    {
        return $this->withinContext($actor, function (int $organizationId, User $currentActor) use ($data): Approach {
            $allowed = ['name', 'description', 'purpose', 'scope', 'objectives', 'review_frequency', 'owning_unit_public_id'];
            if (array_diff(array_keys($data), $allowed) !== []) {
                throw ValidationException::withMessages(['approach' => 'تحتوي البيانات على حقول غير مسموح بها.']);
            }
            $rules = ['owning_unit_public_id' => ['required', 'string']];
            foreach (['name', 'description', 'purpose', 'scope', 'objectives', 'review_frequency'] as $field) {
                $rules[$field] = [in_array($field, ['objectives', 'review_frequency'], true) ? 'nullable' : 'required', 'string',
                    'max:'.($field === 'name' ? 255 : 65535)];
            }
            Validator::make($data, $rules, ['required' => 'هذا الحقل مطلوب.', 'string' => 'يجب إدخال نص صالح.', 'max' => 'تجاوز النص الحد التقني المسموح.'])->validate();
            foreach (['name', 'description', 'purpose', 'scope'] as $field) {
                if (preg_match('/[^\p{Z}\s]/u', $data[$field]) !== 1) {
                    throw ValidationException::withMessages([$field => 'لا يمكن أن يكون النص فارغًا.']);
                }
            }
            $unitId = $data['owning_unit_public_id'];
            $unit = Str::isUlid($unitId) ? OrganizationalUnit::query()->where('organization_id', $organizationId)
                ->where('public_id', $unitId)->lockForUpdate()->first() : null;
            if ($unit === null) {
                throw (new ModelNotFoundException)->setModel(OrganizationalUnit::class);
            }
            $approach = new Approach;
            $approach->forceFill(array_intersect_key($data, array_flip(array_diff($allowed, ['owning_unit_public_id']))));
            $approach->organization_id = $organizationId;
            $approach->owning_unit_id = $unit->id;
            $approach->created_by = $currentActor->getKey();
            $approach->save();

            return $approach;
        });
    }

    /**
     * @internal Shared Approach-only transaction boundary; authenticated callers
     * still need a separately approved action/resource/state Policy before entry.
     */
    public function withinContext(?User $actor, Closure $operation): mixed
    {
        if ($actor === null || ! $actor->exists || $this->context->organizationId() === null
            || $this->context->actorId() !== (int) $actor->getKey()) {
            throw new AuthorizationException;
        }

        return app(TenantAuthorization::class)->withinMutation($actor,
            fn (Organization $organization, User $currentActor) => $operation((int) $organization->id, $currentActor));
    }
}
