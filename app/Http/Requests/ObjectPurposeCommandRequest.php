<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Meta-specific constraints belong only to this enabled admission edge. */
final class ObjectPurposeCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = ['command_id' => ['required', 'uuid'], 'purpose_id' => ['present', 'nullable', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:0'], 'operation' => ['required', Rule::in(['declare', 'replace', 'correct', 'withdraw'])],
            'declaration' => ['present', 'nullable', 'array'], 'confirmations' => ['required', 'array'],
            'affected_revision_ids' => ['present', 'array', 'list', 'max:1000'], 'affected_revision_ids.*' => ['uuid']];
        if ($this->input('operation') !== 'withdraw') {
            foreach (['included_objects', 'excluded_objects'] as $set) {
                $rules['declaration.'.$set] = ['present', 'array', 'list', 'max:50'];
                $rules['declaration.'.$set.'.*.provider'] = ['required', Rule::in(['meta'])];
                $rules['declaration.'.$set.'.*.account_namespace'] = ['required', Rule::in(['meta_ads_account'])];
                $rules['declaration.'.$set.'.*.account_id'] = ['required', 'string', 'regex:/^[0-9]{1,40}$/D'];
                $rules['declaration.'.$set.'.*.object_id'] = ['required', 'string', 'regex:/^[0-9]{1,40}$/D'];
                $rules['declaration.'.$set.'.*.object_kind'] = ['required', Rule::in(['campaign', 'ad_set'])];
            }
        }

        return $rules;
    }

    protected function passedValidation(): void
    {
        if (strlen($this->getContent()) > 131072) {
            throw ValidationException::withMessages(['declaration' => 'Declaration exceeds the technical input limit.']);
        }
        if ($this->isJson()) {
            $raw = json_decode($this->getContent());
            $invalid = ! is_object($raw) || ! isset($raw->affected_revision_ids) || ! is_array($raw->affected_revision_ids);
            if ($this->input('operation') !== 'withdraw') {
                $declaration = $raw->declaration ?? null;
                $invalid = $invalid || ! is_object($declaration);
                foreach (['included_objects', 'excluded_objects', 'conflicts'] as $list) {
                    $invalid = $invalid || ! is_array($declaration->{$list} ?? null);
                }
                if (is_object($declaration) && ($declaration->dtm ?? null) !== null) {
                    $invalid = $invalid || ! is_object($declaration->dtm) || ! is_array($declaration->dtm->countries ?? null) || ! is_array($declaration->dtm->subset ?? null);
                }
            }
            if ($invalid) {
                throw ValidationException::withMessages(['declaration' => 'Submit exact objects and finite lists.']);
            }
        }
    }
}
