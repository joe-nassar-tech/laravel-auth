<?php

declare(strict_types=1);

namespace Joe404\LaravelAuth\Contracts;

interface ExtraFieldTransformerContract
{
    /**
     * Derive a new field value from the validated input.
     *
     * Called after validation passes and before the field is persisted.
     * Useful for normalizing (lowercase email, trim username) or deriving
     * one field from another (username_normalized = strtolower(username)).
     *
     * @param  array<string, mixed>  $validated  Full validated input (email + extras)
     * @return mixed  The value to store under the transformer's target field
     */
    public function transform(array $validated): mixed;
}
