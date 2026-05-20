<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Contacts\Livewire\PartnerForm;
use Modules\Contacts\Livewire\Partners;

Route::middleware('auth')->group(function (): void {
    Route::get('/app/contacts/partner', Partners::class)->name('contacts.partner.index');
    Route::get('/app/contacts/partner/new', PartnerForm::class)->name('contacts.partner.create');
    Route::get('/app/contacts/partner/{id}', PartnerForm::class)
        ->whereNumber('id')
        ->name('contacts.partner.edit');
});
