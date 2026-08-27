<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Illuminate\Validation\ValidationException;

/**
 * Drop-in replacement for `$this->validate()` that, when validation fails,
 * also tells the browser which field to scroll to and focus.
 *
 * Why: on a tall bespoke form (e.g. the limousine booking) a required field
 * can sit below the fold. The stock `validate()` renders the inline error but
 * leaves the viewport where it is, so pressing Save "does nothing" from the
 * user's point of view — the message they need is off-screen. Dispatching the
 * first failing field lets the layout's `scroll-to-error` handler bring it into
 * view and focus it, the same way a native browser form jumps to the first
 * invalid control.
 *
 * The `ValidationException` is re-thrown unchanged, so Livewire still renders
 * every inline `@error` message exactly as before — this only adds the scroll.
 */
trait ScrollsToFirstError
{
    /**
     * @param  array<string, mixed>|null  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     */
    protected function validateFocusing(?array $rules = null, array $messages = [], array $attributes = []): array
    {
        try {
            return $this->validate($rules, $messages, $attributes);
        } catch (ValidationException $e) {
            $firstField = $e->validator->errors()->keys()[0] ?? null;
            if (is_string($firstField) && $firstField !== '') {
                $this->dispatch('scroll-to-error', field: $firstField);
            }

            throw $e;
        }
    }
}
