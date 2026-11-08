<?php

namespace App\Support;

use App\Models\CustomField;
use App\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Validates and applies the tags (by name) and custom field values sent to the
 * API for subnets, addresses and VLANs.
 */
class ApiMetadata
{
    /**
     * @return array{tags: ?list<int>, custom: ?array<string, mixed>}
     */
    public static function validate(Request $request, string $target, ?Model $record = null): array
    {
        $fields = CustomField::for($target);
        $rules = ['tags' => ['sometimes', 'array', 'max:20'], 'tags.*' => ['string', 'exists:tags,name'], 'custom_fields' => ['sometimes', 'array']];
        foreach ($fields as $field) {
            $fieldRules = $field->rules();
            if ($record !== null || ! $field->required) {
                $fieldRules[0] = 'nullable';
            }
            $rules['custom_fields.'.$field->key] = $fieldRules;
        }
        $data = Validator::make($request->only(['tags', 'custom_fields']), $rules)->validate();

        $custom = null;
        if (array_key_exists('custom_fields', $data) || ($record === null && $fields->contains('required', true))) {
            $custom = (array) ($record?->custom_values ?? []);
            foreach ($fields as $field) {
                if (array_key_exists($field->key, $data['custom_fields'] ?? [])) {
                    $value = $data['custom_fields'][$field->key];
                    $custom[$field->key] = $value === null || $value === '' ? null : ($field->type === 'number' ? $value + 0 : $value);
                }
            }
            $custom = array_filter(array_intersect_key($custom, $fields->keyBy('key')->all()), fn (mixed $value): bool => $value !== null);
        }

        return [
            'tags' => array_key_exists('tags', $data) ? Tag::whereIn('name', $data['tags'])->pluck('id')->all() : null,
            'custom' => $custom,
        ];
    }

    /** @param array{tags: ?list<int>, custom: ?array<string, mixed>} $metadata */
    public static function fill(Model $record, array $metadata): void
    {
        if ($metadata['custom'] !== null) {
            $record->custom_values = $metadata['custom'] ?: null;
        }
    }

    /** @param array{tags: ?list<int>, custom: ?array<string, mixed>} $metadata */
    public static function syncTags(Model $record, array $metadata): void
    {
        if ($metadata['tags'] !== null) {
            $record->syncTagsWithAudit($metadata['tags']);
        }
    }
}
