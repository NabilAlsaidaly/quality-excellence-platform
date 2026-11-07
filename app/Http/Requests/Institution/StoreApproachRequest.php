<?php

namespace App\Http\Requests\Institution;

use App\Http\Controllers\Institution\ApproachController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Inert until explicitly registered behind InstitutionSession and an approved Policy. */
final class StoreApproachRequest extends FormRequest
{
    public function authorize(): bool
    {
        ApproachController::authorizeRequest($this, 'create');

        return true;
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:255', 'description' => 'required|string|max:65535', 'purpose' => 'required|string|max:65535', 'scope' => 'required|string|max:65535', 'objectives' => 'nullable|string|max:65535', 'review_frequency' => 'nullable|string|max:65535', 'owning_unit_public_id' => 'required|string|ulid'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->except('_token', '_method')), array_keys($this->rules())) !== []) {
                $validator->errors()->add('approach', __('institution_approaches.unknown_fields', [], 'ar'));
            }
        }];
    }

    public function messages(): array
    {
        return ['required' => 'هذا الحقل مطلوب.', 'string' => 'أدخل نصًا صالحًا.', 'max' => 'تجاوز النص الحد التقني المسموح.',
            'ulid' => 'المعرّف غير صالح.', 'regex' => 'أعد فتح النموذج قبل الحفظ.', 'accepted' => 'يجب تأكيد نقل الملكية.'];
    }

    protected function getRedirectUrl(): string
    {
        return ApproachController::destination($this, 'create', false);
    }
}
