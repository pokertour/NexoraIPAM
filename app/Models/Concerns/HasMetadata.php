<?php

namespace App\Models\Concerns;

use App\Models\Tag;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Adds tags and custom field values to an inventory model.
 */
trait HasMetadata
{
    public function initializeHasMetadata(): void
    {
        $this->mergeCasts(['custom_values' => 'array']);
        $this->mergeFillable(['custom_values']);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->orderBy('name');
    }

    /**
     * Replaces the tags and records the difference in the audit trail.
     *
     * @param  list<int>  $ids
     */
    public function syncTagsWithAudit(array $ids): void
    {
        $before = $this->tags()->pluck('name')->all();
        $this->tags()->sync($ids);
        $after = $this->tags()->pluck('name')->all();
        if ($before !== $after) {
            Audit::record('updated', $this, ['tags' => [$before ?: null, $after ?: null]]);
        }
        $this->unsetRelation('tags');
    }
}
