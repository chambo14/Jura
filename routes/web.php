<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AttachmentPreviewController;
use App\Http\Controllers\ComiteExportController;
use Illuminate\Support\Facades\Route;

// L'application n'a pas de page vitrine : on entre par la connexion.
Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

    Route::livewire('projets', 'pages::projets.index')->name('projets.index');
    Route::livewire('projets/nouveau', 'pages::projets.form')->name('projets.create');
    Route::livewire('projets/{project}/modifier', 'pages::projets.form')->name('projets.edit');
    Route::livewire('projets/{project}', 'pages::projets.show')->name('projets.show');

    Route::livewire('flash-reports', 'pages::flash-reports.index')->name('flash-reports.index');
    Route::livewire('flash-reports/{flashReport}', 'pages::flash-reports.edit')->name('flash-reports.edit');

    Route::livewire('plan-de-charge', 'pages::plan-de-charge.index')->name('plan-de-charge.index');
    Route::livewire('plan-de-charge/{user}', 'pages::plan-de-charge.show')->name('plan-de-charge.show');

    Route::livewire('tableau', 'pages::tableau')->name('tableau');

    Route::livewire('analyse', 'pages::analyse')->name('analyse');

    // Les fichiers déposés ne sont jamais servis depuis le disque public :
    // le contrôleur revérifie l'accès au projet à chaque téléchargement.
    Route::get('documents/{attachment}', AttachmentController::class)->name('documents.download');

    // Lire sans télécharger. Le contrôleur n'affiche que les formats inertes
    // et refuse tout le reste : la page est servie dans l'origine du site.
    Route::get('documents/{attachment}/lire', AttachmentPreviewController::class)->name('documents.lire');

    Route::livewire('comite', 'pages::comite')->name('comite');

    // La présentation de la semaine, au format attendu par le comité.
    Route::get('comite/export', ComiteExportController::class)->name('comite.export');

    Route::livewire('referentiel', 'pages::referentiel')->name('referentiel');

    Route::livewire('utilisateurs', 'pages::utilisateurs.index')->name('utilisateurs.index');
    // Les profils sont un onglet de l'écran Utilisateurs ; l'ancienne adresse y mène.
    Route::redirect('profils', 'utilisateurs?onglet=profils')->name('profils.index');
});

require __DIR__.'/settings.php';
