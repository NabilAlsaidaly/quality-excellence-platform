<?php

namespace App\Http\Requests\Institution;

use App\Http\Controllers\Institution\ApproachController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Inert until explicitly registered behind InstitutionSession and an approved Policy. */
final class UpdateApproachRequest extends FormRequest
{
    public function authorize(): bool
    {
        ApproachController::authorizeRequest($this, 'updateMetadata');

        return true;
    }

    public function rules(): array
    {
        return ['name' => 'sometimes|required|string|max:255', 'description' => 'sometimes|required|string|max:65535', 'purpose' => 'sometimes|required|string|max:65535', 'scope' => 'sometimes|required|string|max:65535', 'objectives' => 'nullable|string|max:65535', 'review_frequency' => 'nullable|string|max:65535', 'edit_token' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']];
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
        return ApproachController::destination($this, 'edit', true);
    }
}
