<?php

use App\Enums\Permission;
use App\Http\Controllers\Auth\MicrosoftController;
use App\Http\Controllers\OutreachAttachmentsController;
use App\Http\Controllers\OutreachImagesController;
use App\Livewire\BusinessDevelopment\DevelopersIndex;
use App\Livewire\BusinessDevelopment\DevelopersShow;
use App\Livewire\BusinessDevelopment\OutreachIndex;
use App\Livewire\BusinessDevelopment\OutreachShow;
use App\Livewire\BusinessDevelopment\Pipeline;
use Illuminate\Support\Facades\Route;

// Loaded inside the authenticated group in routes/web.php.

Route::middleware('can:'.Permission::ViewBusinessDevelopment->value)->group(function () {
    Route::get('pipeline', Pipeline::class)->name('pipeline');
    Route::get('developers', DevelopersIndex::class)->name('developers.index');
    Route::get('developers/{developerKey}', DevelopersShow::class)->name('developers.show');
    Route::get('outreach', OutreachIndex::class)->name('outreach.index');
    Route::get('outreach/{prospectKey}', OutreachShow::class)->name('outreach.show');
    Route::get('outreach-images/{name}', [OutreachImagesController::class, 'show'])
        ->where('name', '[A-Za-z0-9._-]+')
        ->name('outreach.images');

    Route::middleware('can:'.Permission::EditBusinessDevelopment->value)->group(function () {
        Route::get('outreach-outlook/connect', [MicrosoftController::class, 'connectOutlook'])->name('outreach.outlook.connect');
        Route::post('outreach-outlook/disconnect', [MicrosoftController::class, 'disconnectOutlook'])->name('outreach.outlook.disconnect');

        Route::post('outreach-attachments', [OutreachAttachmentsController::class, 'store'])->name('outreach.attachments.store');
        Route::put('outreach-attachments/{attachment}/chunks/{position}', [OutreachAttachmentsController::class, 'chunk'])
            ->whereNumber('position')
            ->name('outreach.attachments.chunk');
        Route::post('outreach-attachments/{attachment}/complete', [OutreachAttachmentsController::class, 'complete'])->name('outreach.attachments.complete');
    });

    Route::get('outreach-attachments/{attachment}', [OutreachAttachmentsController::class, 'show'])->name('outreach.attachments.show');
});
