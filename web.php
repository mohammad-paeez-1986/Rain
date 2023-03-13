<?php

// use Illuminate\Foundation\Application;

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\AttachmentControll;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuditReportController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientImportController;
use App\Http\Controllers\ClientStatusController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\ContractorInvoiceController;
use App\Http\Controllers\DeployController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ManagerController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function(Request $request) {
    $request->session()?->reflash();
    $user = $request->user();
    $route = 'dashboard';

    if (!$user) {
        $route = 'login';
    }

    return redirect()->route($route);
})->name('home');

Route::prefix('audits-api')->controller(AuditController::class)->group(function() {
    Route::get('calendar/subscribe/{user}', 'iCal')->name('audits.calendar.subscribe');
    Route::get('export/excel/{hash}', 'exportExcel')->name('audits.export.excel');
});

Route::get('dashboard/{role?}', 'App\Http\Controllers\DashboardController@dashboard')->name('dashboard')->middleware('auth', 'web');

Route::prefix('profile')
    ->controller(RegisteredUserController::class)
    ->group(function(): void {
        Route::get('/', 'profilePage')->name('profile');

        Route::put('password', 'changePassword')->name('profile.password');
        Route::post('update', 'updateProfile')->name('profile.save');
        Route::post('profile-picture', 'updateProfilePicture')->name('profile.update-profile-picture');
        Route::post('company-logo', 'updateCompanyLogo')->name('profile.update-company-logo');

        Route::delete('remove-profile-picture', 'removeProfilePicture')->name('profile.remove-photo');

        Route::prefix('bank-accounts')->name('profile.bank-accounts.')->group(function(): void {
            Route::post('create', 'createBankAccount')->name('create');
            Route::put('edit/{bankAccount}', 'updateBankAccount')->name('update');
            Route::delete('remove/{bankAccount}', 'removeBankAccount')->name('remove');
        });

        Route::prefix('licenses')->name('profile.licenses.')->group(function(): void {
            Route::post('create', 'createLicense')->name('create');
            Route::put('edit/{license}', 'updateLicense')->name('update');
            Route::delete('remove/{license}', 'removeLicense')->name('remove');
        });
    })
;

Route::prefix('tickets')
    ->name('tickets.')
    ->controller(TicketController::class)
    ->group(function(): void {
        Route::get('/', 'index')->name('index');
        Route::get('view/{ticket}', 'show')->name('view');
        Route::get('create', 'create')->name('create');

        Route::post('create', 'store');
        Route::post('view/{ticket}', 'answer')->name('answer');
        Route::post('close/{ticket}', 'close')->name('close');
    })
;

Route::prefix('reports')
    ->name('reports.')
    ->controller(AuditReportController::class)
    ->group(function(): void {
        Route::get('/audit-dates', 'dateReport')->name('audit-dates');
        Route::get('/audit-lead-source', 'leadReports')->name('audit-lead-source');
        Route::get('/audit-overview', 'auditOverview')->name('audit-overview');
        Route::post('/audit-overview', 'CreateAuditOverview')->name('audit-overview');
        Route::post('/audit-overview-more', 'moreAuditOverview')->name('audit-overview-more');
        Route::prefix('export')->name('export.')->group(function(): void {
            Route::get('audit-dates/csv', 'exportAuditDateCSV')->name('audit-date-csv');
            Route::get('audit-lead-source/csv', 'exportAuditLeadSourceCSV')->name('audit-lead-source-csv');
            Route::get('audit-overview/csv', 'exportAuditOverviewCSV')->name('audit-overview-csv');
        });
    })
;

Route::prefix('users')
    ->name('users.')
    ->controller(UserController::class)
    ->group(function(): void {
        Route::get('/', 'index')->name('index');
        Route::get('create', 'create')->name('create');
        Route::get('edit/{user}', 'edit')->name('update');

        Route::post('create', 'store');

        Route::post('login-as/{user}', 'loginAs')->name('login-as');

        Route::prefix('edit/{user}')->name('update.')->group(function(): void {
            Route::post('general', 'updateGeneral')->name('general');
            Route::post('password', 'changePassword')->name('password');
            Route::post('address', 'updateAddress')->name('address');
            Route::post('organization', 'updateOrganization')->name('organization');
            Route::post('accounting', 'updateAccounting')->name('accounting');
            Route::post('roles-permissions', 'updateRolesAndPermissions')->name('roles-permissions');
            Route::post('limitations', 'updateLimitations')->name('limitations');
            Route::post('licenses', 'updateLicenses')->name('licenses');

            Route::prefix('bank-accounts')->name('bank-accounts.')->group(function(): void {
                Route::post('create', 'createBankAccount')->name('create');
                Route::put('edit/{bankAccount}', 'updateBankAccount')->name('update');
                Route::delete('remove/{bankAccount}', 'removeBankAccount')->name('remove');
            });

            Route::prefix('licenses')
                ->group(function(): void {
                    Route::post('create', 'storeLicense')->name('licenses.create');
                    Route::put('update/{license}', 'updateLicense')->name('licenses.update');
                    Route::delete('remove/{license}', 'removeLicense')->name('licenses.remove');
                })
            ;
        });

        Route::post('{user}/change-status', 'changeStatus')->name('change-status');
        Route::delete('remove/{user}', 'destroy')->name('remove');

        Route::prefix('invite-links')->name('invite-links.')->group(function(): void {
            Route::get('/', 'inviteLinks')->name('index');
            Route::get('create', 'createInviteLink')->name('create');

            Route::post('create', 'storeInviteLink');
            Route::delete('remove/{inviteLink}', 'removeInviteLink')->name('remove');
        });
    })
;

Route::middleware('admin-only')
    ->name('admin.')
    ->prefix('admin')
    ->group(function(): void {
        Route::get('dashboard', 'App\Http\Controllers\AdminController@dashboard')->name('dashboard');

        Route::prefix('subscriptions')
            ->controller(SubscriptionController::class)
            ->group(function(): void {
                Route::get('/', 'adminSubscriptions')->name('subscriptions');
                Route::post('/change-status/{subscription}', 'adminChangeSubscriptionStatus')->name('subscriptions.change-status');
            })
        ;

        Route::prefix('plans')
            ->controller(PlanController::class)
            ->group(function(): void {
                Route::get('/', 'adminPlans')->name('plans');
                Route::get('create', 'adminCreatePlan')->name('plans.create');
                Route::get('edit/{plan}', 'adminEditPlan')->name('plans.update');
                // Route::get('remove/{plan}', 'adminRemovePlan')->name('plans.remove');

                Route::put('create', 'store');
                Route::post('edit/{plan}', 'update');
                Route::delete('trash/{plan}', 'destroy')->name('plans.remove');
                Route::post('restore/{plan}', 'restore')->name('plans.restore')->withTrashed();
            })
        ;

        Route::prefix('companies')
            ->name('companies.')
            ->controller(CompanyController::class)
            ->group(function(): void {
                Route::get('/', 'index')->name('index');
                Route::get('edit/{company}', 'edit')->name('update');
                Route::get('create', 'create')->name('create');
                Route::get('search', 'search')->name('search');

                Route::post('create', 'store');
                Route::put('edit/{company}', 'update');
                Route::delete('remove/{company}', 'remove');
            })
        ;
    })
;

Route::middleware(['auth', 'verified', 'subscribed-only'])->group(function(): void {
    Route::middleware('role:manager')
        ->name('manager.')
        ->controller(ManagerController::class)
        ->prefix('manager')
        ->group(function(): void {
            // Route::get('dashboard', 'dashboard')->name('dashboard');
        })
    ;

    Route::prefix('requests')
        ->controller(RequestController::class)
        ->group(function(): void {
            Route::get('/', 'index')->name('requests');

            Route::prefix('export')->name('requests.export.')->group(function(): void {
                Route::get('csv', 'exportCSV')->name('csv');
                Route::get('audit/pdf/{req}', 'exportAuditAsPdf')->name('audit.pdf');
            });

            Route::post('{req}/change-status', 'changeStatus')->name('requests.change-status');

            Route::get('search-users', 'searchUsers')->name('requests.search-users');

            Route::get('create', 'create')->name('requests.create');
            Route::post('create', 'store');

            Route::get('edit/{req}', 'edit')->name('requests.update');
            Route::post('edit/{req}', 'update');
            Route::post('add-attachments/{req}', 'addAttachments')->name('requests.add-attachments');
            Route::delete('{req}', 'destroy')->name('requests.remove');
            Route::post('attachments/{req}/{media}/caption', 'captionAttachment')->name('requests.attachments.caption');
            Route::delete('attachment/{req}/{media}/remove', 'removeFile')->name('requests.remove-file');
        })
    ;

    Route::prefix('clients')
        ->controller(ClientController::class)
        ->group(function(): void {
            Route::get('/', 'index')->name('clients');
            Route::get('view/{client}', 'show')->name('clients.view');
            Route::get('search', 'search')->name('clients.search');

            Route::prefix('import')->controller(ClientImportController::class)->name('clients.import.')->group(function(): void {
                Route::prefix('csv')->name('csv.')->group(function(): void {
                    Route::get('/', 'index')->name('index');
                    Route::get('create', 'create')->name('create');
                    Route::post('create', 'store');

                    Route::post('merge/{duplicate}', 'merge')->name('merge');

                    Route::prefix('{import}')->group(function(): void {
                        Route::get('view', 'show')->name('view');
                        Route::get('csv', 'download')->name('download');

                        Route::post('import', 'import')->name('import');
                        Route::post('validate', 'validateCSV')->name('validate');

                        Route::delete('remove', 'destroy')->name('remove');
                    });
                });
            });

            Route::prefix('export')->name('clients.export.')->group(function(): void {
                Route::get('csv', 'exportCSV')->name('csv');
            });

            Route::post('create', 'store')->name('clients.create');
            Route::post('duplicate/{client}', 'duplicate')->name('clients.duplicate');
            Route::put('update/{client}', 'update')->name('clients.update');
            Route::delete('remove/{client}', 'destroy')->name('clients.remove');
            Route::post('change-status/{client}/{clientStatus}', 'changeStatus')->name('clients.change-status');
            Route::post('comment/{client}', 'comment')->name('clients.comment');
            Route::post('comment/lead-generator/{client}', 'leadGeneratorComment')->name('clients.comment.lg');
            Route::post('notes/supervisor/{client}', 'updateSupervisorNotes')->name('clients.supervisor-notes.update');
            Route::post('notes/lead-generator/{client}', 'updateLeadGeneratorNotes')->name('clients.lead-generator-notes.update');
            Route::post('/{client}/audits/assign', 'assignAudit')->name('clients.assign-audit');
        })
    ;

    Route::prefix('audits')
        ->name('audits.')
        ->controller(AuditController::class)
        ->group(function(): void {
            Route::get('/', 'index')->name('index');
            Route::get('/calendar', 'calendar')->name('calendar');
            Route::get('/map-view', 'mapView')->name('map-view');
            Route::get('/calendar/audits', 'calendarAudits')->name('calendar.audits');
            Route::get('view/{audit}', 'show')->name('view');
            Route::get('{audit}/export/pdf', 'exportAsPdf')->name('export.pdf');
            Route::post('{audit}/email/pdf', 'emailPDF')->name('email.pdf');
            Route::get('export/csv', 'exportCSV')->name('export.csv');

            Route::get('create', 'create')->name('create');
            Route::post('create', 'store');

            Route::get('edit/{audit}', 'edit')->name('update');
            Route::put('edit/{audit}', 'update');
            Route::post('assign-to-auditor/{audit}', 'assignToAuditor')->name('assign-auditor');
            Route::put('edit/{audit}/receive', 'updateReceive')->name('update.receive');
            Route::delete('{audit}', 'destroy')->name('remove');
        })
    ;

    Route::prefix('accounting')->group(function(): void {
        Route::prefix('invoices')
            ->name('invoices.')
            ->controller(InvoiceController::class)
            ->group(function(): void {
                Route::get('/', 'index')->name('index');
                Route::get('view/{invoice}', 'show')->name('view');
                Route::post('{invoice}/comment', 'comment')->name('comment');
                Route::post('{invoice}/status/change', 'changeStatus')->name('change-status');
                Route::get('{invoice}/export/pdf', 'exportAsPdf')->name('export.pdf');
                // Route::get('{invoice}/export/html', 'exportAsHtml')->name('export.html');

                Route::prefix('export')->name('export.')->group(function(): void {
                    Route::get('csv', 'exportCSV')->name('csv');
                });

                Route::match(['get', 'post'], 'create/{step?}', 'create')->name('create');
                Route::post('create', 'store');

                Route::get('edit/{invoice}', 'edit')->name('update');
                Route::put('edit/{invoice}', 'update');
                Route::put('edit/{invoice}/extra-costs', 'extraCosts')->name('extra-costs');

                Route::delete('{invoice}', 'destroy')->name('remove');
            })
        ;

        Route::prefix('invoices/contractor')
            ->name('contractor-invoices.')
            ->controller(ContractorInvoiceController::class)
            ->group(function(): void {
                Route::get('/', 'index')->name('index');
                Route::get('view/{invoice}', 'show')->name('view');
                Route::post('{invoice}/comment', 'comment')->name('comment');
                Route::post('{invoice}/status/change', 'changeStatus')->name('change-status');
                Route::get('{invoice}/export/pdf', 'exportAsPdf')->name('export.pdf');
                Route::get('{invoice}/export/pdf/work-order', 'exportWorkOrder')->name('export.work-order');
                // Route::get('{invoice}/export/html', 'exportAsHtml')->name('export.html');

                Route::prefix('export')->name('export.')->group(function(): void {
                    Route::get('csv', 'exportCSV')->name('csv');
                });

                Route::match(['get', 'post'], 'create/{step?}', 'create')->name('create');
                Route::post('create', 'store');

                Route::get('edit/{invoice}', 'edit')->name('update');
                Route::put('edit/{invoice}', 'update');
                Route::put('edit/{invoice}/extra-costs', 'extraCosts')->name('extra-costs');

                Route::delete('{invoice}', 'destroy')->name('remove');
            })
        ;

        Route::get('payments', [AuditController::class, 'payments'])->name('payments.index');
        Route::get('balance-sheet', [UserController::class, 'balanceSheet'])->name('accounting.balance-sheet');
        Route::get('balance-sheet/contractor', [UserController::class, 'contractorBalanceSheet'])->name('accounting.balance-sheet.contractor');
    });

    Route::prefix('users')
        ->controller(UserController::class)
        ->group(function(): void {
            Route::get('search', 'search')->name('users.search');
        })
    ;

    Route::prefix('api-tokens')
        ->controller(ApiTokenController::class)
        ->name('api.')
        ->group(function(): void {
            Route::get('/', 'index')->name('tokens.index');
            Route::get('view', 'view')->name('tokens.view');
            Route::get('create', 'create')->name('tokens.create');
            Route::get('edit/{token}', 'edit')->name('tokens.update');

            Route::post('create', 'store');
            Route::post('edit/{token}', 'update');
            Route::delete('revoke/{token}', 'revoke')->name('tokens.revoke');
        })
    ;
});

Route::prefix('subscribe')
    ->controller(SubscriptionController::class)
    ->group(function(): void {
        Route::get('/', 'subscribe')->name('subscribe');
        Route::post('/', 'saveSubscription');
    })
;

Route::prefix('attachments')
    ->name('attachments.')
    ->controller(AttachmentControll::class)
    ->group(function(): void {
        Route::get('/', 'subscribe')->name('subscribe');
        Route::post('/{media}/caption', 'caption')->name('caption');
        Route::delete('/{media}', 'destroy')->name('remove');
    })
;

Route::prefix('settings')
    ->name('settings.')
    ->controller(SettingsController::class)
    ->group(function(): void {
        Route::get('company', 'companySettings')->name('company');
        Route::get('notifications', 'notifications')->name('notifications');
        Route::get('emails', 'emails')->name('emails');
        Route::get('emails/preview/{mail}', 'previewMail')->name('emails.preview');
        Route::get('customizations', 'customizations')->name('customizations');
        Route::get('import', 'import')->name('import');
        Route::get('export', 'export')->name('export');

        Route::put('company', 'storeCompanySettings');
        Route::put('emails', 'saveEmails');
        // Route::put('emails/templates', 'saveEmails')->name('emails.templates');
        Route::put('customizations', 'storeCustomizations');

        Route::prefix('client-statuses')
            ->controller(ClientStatusController::class)
            ->group(function(): void {
                Route::get('/', 'index')->name('client-statuses');
                Route::post('/create', 'store')->name('client-statuses.create');
                Route::put('update/{clientStatus}', 'update')->name('client-statuses.update');
                Route::delete('trash/{clientStatus}', 'trash')->name('client-statuses.trash');
                Route::delete('remove/{clientStatus}', 'remove')->name('client-statuses.remove')->withTrashed();
                Route::post('restore/{clientStatus}', 'restore')->name('client-statuses.restore')->withTrashed();
            })
        ;

        Route::prefix('licenses')
            ->group(function(): void {
                Route::get('/', 'licenses')->name('licenses');
                Route::post('create', 'storeLicense')->name('licenses.create');
                Route::put('update/{license}', 'updateLicense')->name('licenses.update');
                Route::delete('remove/{license}', 'removeLicense')->name('licenses.remove');
            })
        ;
    })
;

Route::prefix('notifications')->name('notifications.')->controller(NotificationController::class)->group(function(): void {
    Route::prefix('json')->name('json.')->group(function(): void {
        Route::get('unreads', 'unreads')->name('unreads');
        Route::get('all', 'all')->name('all');
    });

    Route::get('{notification}/mark-as-read', 'markAsRead')->name('read');
    Route::get('mark-all-as-read', 'markAllAsRead')->name('read.all');
});

Route::get('manifest.json', 'App\Http\Controllers\PWAController@manifest');
Route::get('manifest.webmanifest', 'App\Http\Controllers\PWAController@manifest');

Route::post('/git/deploy', [DeployController::class, 'deploy'])->withoutMiddleware(VerifyCsrfToken::class);

Route::fallback(fn () => inertia('Errors/404'));

require __DIR__.'/auth.php';
