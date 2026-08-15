<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CenterController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\ZoneController;
use App\Http\Controllers\Assignments\AreaAssignmentController;
use App\Http\Controllers\Assignments\GroupController;
use App\Http\Controllers\Assignments\TargetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Bal\AnalysisController as BalAnalysisController;
use App\Http\Controllers\Bal\CompletionController as BalCompletionController;
use App\Http\Controllers\Bal\DashboardController as BalDashboardController;
use App\Http\Controllers\Bal\GroupController as BalGroupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Field\HomeVisitController;
use App\Http\Controllers\Field\MyTargetController;
use App\Http\Controllers\Field\ReminderController;
use App\Http\Controllers\Registration\FamilyController;
use App\Http\Controllers\Registration\ImportController;
use App\Http\Controllers\Registration\KaryakarController;
use App\Http\Controllers\Monitoring\AnalysisController;
use App\Http\Controllers\Monitoring\ReportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Support\AnnouncementController;
use App\Http\Controllers\Support\CorrectionRequestController;
use App\Http\Controllers\Support\FamilyTimeController;
use App\Http\Controllers\Support\InventoryController;
use App\Http\Controllers\Support\SharedContentController;
use App\Http\Controllers\Support\StickyNoteController;
use App\Http\Controllers\Support\SupportRequestController;
use App\Http\Controllers\Support\TestimonialController;
use Illuminate\Support\Facades\Route;

Route::get('/health/ready', HealthController::class)->name('health.ready');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/admin/zones', [ZoneController::class, 'index'])->middleware('permission:manage_zones')->name('zones.index');
    Route::post('/admin/zones', [ZoneController::class, 'store'])->middleware('permission:manage_zones')->name('zones.store');
    Route::put('/admin/zones/{zone}', [ZoneController::class, 'update'])->middleware(['permission:manage_zones', 'scope'])->name('zones.update');

    Route::get('/admin/centers', [CenterController::class, 'index'])->middleware('permission:view_center')->name('centers.index');
    Route::post('/admin/centers', [CenterController::class, 'store'])->middleware('permission:manage_centers')->name('centers.store');
    Route::put('/admin/centers/{center}', [CenterController::class, 'update'])->middleware(['permission:manage_centers', 'scope'])->name('centers.update');

    Route::get('/admin/users', [UserController::class, 'index'])->middleware('permission:manage_users,reset_user_passwords')->name('users.index');
    Route::post('/admin/users', [UserController::class, 'store'])->middleware('permission:manage_users')->name('users.store');
    Route::put('/admin/users/{user}', [UserController::class, 'update'])->middleware('permission:manage_users')->name('users.update');
    Route::put('/admin/users/{user}/password', [UserController::class, 'resetPassword'])->middleware('permission:reset_user_passwords')->name('users.password.reset');

    Route::get('/admin/audit-logs', AuditLogController::class)->middleware('permission:view_audit_logs')->name('audit-logs.index');
    Route::get('/admin/settings', SettingsController::class)->middleware('permission:manage_master_data')->name('settings.index');
    Route::put('/admin/settings/roles/{role}/permissions', [SettingsController::class, 'updateRolePermissions'])->middleware('permission:manage_roles')->name('settings.roles.permissions');
    Route::post('/admin/settings/areas', [SettingsController::class, 'storeArea'])->middleware('permission:manage_master_data')->name('settings.areas.store');
    Route::post('/admin/settings/societies', [SettingsController::class, 'storeSociety'])->middleware('permission:manage_master_data')->name('settings.societies.store');

    Route::get('/registration/families', [FamilyController::class, 'index'])->middleware('permission:register_family')->name('families.index');
    Route::post('/registration/families', [FamilyController::class, 'store'])->middleware('permission:register_family')->name('families.store');
    Route::put('/registration/families/{family}', [FamilyController::class, 'update'])->middleware('permission:register_family')->name('families.update');
    Route::get('/registration/families/{family}', [FamilyController::class, 'show'])->middleware('permission:register_family')->name('families.show');

    Route::get('/registration/karyakars', [KaryakarController::class, 'index'])->middleware('permission:register_karyakar')->name('karyakars.index');
    Route::post('/registration/karyakars', [KaryakarController::class, 'store'])->middleware('permission:register_karyakar')->name('karyakars.store');
    Route::post('/registration/karyakars/nominate', [KaryakarController::class, 'nominate'])->middleware('permission:register_karyakar')->name('karyakars.nominate');
    Route::post('/registration/karyakars/{karyakar}/decision', [KaryakarController::class, 'decide'])->middleware('permission:approve_karyakar')->name('karyakars.decide');

    Route::get('/registration/imports', [ImportController::class, 'index'])->middleware('permission:register_family')->name('imports.index');
    Route::post('/registration/imports', [ImportController::class, 'store'])->middleware('permission:register_family')->name('imports.store');

    Route::get('/assignments/groups', [GroupController::class, 'index'])->middleware('permission:view_own_assignments')->name('groups.index');
    Route::post('/assignments/groups', [GroupController::class, 'store'])->middleware('permission:create_group')->name('groups.store');
    Route::get('/assignments/groups/{group}', [GroupController::class, 'show'])->middleware('permission:view_own_assignments')->name('groups.show');
    Route::post('/assignments/groups/{group}/families', [GroupController::class, 'assignFamily'])->middleware('permission:view_own_assignments')->name('groups.families.assign');
    Route::post('/assignments/groups/{group}/remaining-family', [GroupController::class, 'selectRemainingFamily'])->middleware('permission:view_own_assignments')->name('groups.remaining.select');
    Route::post('/assignments/groups/{group}/remaining-family/report', [GroupController::class, 'reportNewRemainingFamily'])->middleware('permission:view_own_assignments')->name('groups.remaining.report');
    Route::post('/assignments/groups/{group}/remaining-family-reports/{report}/review', [GroupController::class, 'reviewRemainingFamilyReport'])->middleware('permission:assign_transfer_families')->name('groups.remaining.review');
    Route::post('/assignments/groups/{group}/activate', [GroupController::class, 'activate'])->middleware('permission:create_group')->name('groups.activate');
    Route::post('/assignments/groups/{group}/families/{assignment}/transfer', [GroupController::class, 'transferFamily'])->middleware('permission:assign_transfer_families')->name('groups.families.transfer');

    Route::get('/assignments/areas', [AreaAssignmentController::class, 'index'])->middleware('permission:assign_area_society')->name('area-assignments.index');
    Route::post('/assignments/areas', [AreaAssignmentController::class, 'store'])->middleware('permission:assign_area_society')->name('area-assignments.store');

    Route::get('/assignments/targets', [TargetController::class, 'index'])->middleware('permission:assign_target')->name('targets.index');
    Route::post('/assignments/targets', [TargetController::class, 'store'])->middleware('permission:assign_target')->name('targets.store');

    Route::get('/field/my-target', MyTargetController::class)->middleware('permission:mark_home_visit')->name('field.my-target');
    Route::post('/field/home-visits/{assignment}', [HomeVisitController::class, 'store'])->middleware('permission:mark_home_visit')->name('field.home-visits.store');
    Route::get('/field/reminders', ReminderController::class)->middleware('permission:view_own_assignments')->name('field.reminders');


    Route::get('/bal-pravruti', BalDashboardController::class)->middleware('permission:access_bal_pravruti')->name('bal.dashboard');
    Route::get('/bal-pravruti/groups', [BalGroupController::class, 'index'])->middleware('permission:access_bal_pravruti')->name('bal.groups.index');
    Route::post('/bal-pravruti/groups', [BalGroupController::class, 'store'])->middleware('permission:manage_bal_groups')->name('bal.groups.store');
    Route::get('/bal-pravruti/groups/{group}', [BalGroupController::class, 'show'])->middleware('permission:access_bal_pravruti')->name('bal.groups.show');
    Route::get('/bal-pravruti/completions', [BalCompletionController::class, 'index'])->middleware('permission:submit_bal_completion')->name('bal.completions.index');
    Route::post('/bal-pravruti/groups/{group}/completions', [BalCompletionController::class, 'store'])->middleware('permission:submit_bal_completion')->name('bal.completions.store');
    Route::get('/bal-pravruti/analysis', BalAnalysisController::class)->middleware('permission:view_bal_analysis')->name('bal.analysis');

    Route::get('/monitoring/analysis', AnalysisController::class)->middleware('permission:view_reports_analysis')->name('monitoring.analysis');
    Route::get('/monitoring/reports', [ReportController::class, 'index'])->middleware('permission:view_reports_analysis')->name('monitoring.reports');
    Route::get('/monitoring/reports/export', [ReportController::class, 'export'])->middleware('permission:view_reports_analysis')->name('monitoring.reports.export');

    Route::get('/support/announcements', [AnnouncementController::class, 'index'])->middleware('permission:view_announcements')->name('support.announcements.index');
    Route::post('/support/announcements', [AnnouncementController::class, 'store'])->middleware('permission:manage_announcements')->name('support.announcements.store');
    Route::put('/support/announcements/{announcement}', [AnnouncementController::class, 'update'])->middleware('permission:manage_announcements')->name('support.announcements.update');

    Route::get('/support/family-time', [FamilyTimeController::class, 'index'])->middleware('permission:view_family_time')->name('support.family-time.index');
    Route::post('/support/family-time/schedules', [FamilyTimeController::class, 'storeSchedule'])->middleware('permission:manage_family_time')->name('support.family-time.schedules.store');
    Route::post('/support/family-time/schedules/{schedule}/complete', [FamilyTimeController::class, 'complete'])->middleware('permission:record_family_time')->name('support.family-time.complete');

    Route::get('/support/content', [SharedContentController::class, 'index'])->middleware('permission:view_shared_content')->name('support.content.index');
    Route::post('/support/content', [SharedContentController::class, 'store'])->middleware('permission:manage_shared_content')->name('support.content.store');
    Route::put('/support/content/{content}', [SharedContentController::class, 'update'])->middleware('permission:manage_shared_content')->name('support.content.update');
    Route::delete('/support/content/{content}', [SharedContentController::class, 'destroy'])->middleware('permission:manage_shared_content')->name('support.content.destroy');

    Route::get('/support/testimonials', [TestimonialController::class, 'index'])->middleware('permission:view_testimonials')->name('support.testimonials.index');
    Route::post('/support/testimonials', [TestimonialController::class, 'store'])->middleware('permission:submit_testimonial')->name('support.testimonials.store');
    Route::post('/support/testimonials/{testimonial}/review', [TestimonialController::class, 'review'])->middleware('permission:manage_testimonials')->name('support.testimonials.review');

    Route::get('/support/inventory', [InventoryController::class, 'index'])->middleware('permission:view_inventory')->name('support.inventory.index');
    Route::post('/support/inventory', [InventoryController::class, 'store'])->middleware('permission:manage_inventory')->name('support.inventory.store');
    Route::post('/support/inventory/{item}/transactions', [InventoryController::class, 'transact'])->middleware('permission:manage_inventory')->name('support.inventory.transact');

    Route::get('/support/sticky-notes', [StickyNoteController::class, 'index'])->middleware('permission:use_sticky_notes')->name('support.sticky-notes.index');
    Route::post('/support/sticky-notes', [StickyNoteController::class, 'store'])->middleware('permission:use_sticky_notes')->name('support.sticky-notes.store');
    Route::put('/support/sticky-notes/{note}', [StickyNoteController::class, 'update'])->middleware('permission:use_sticky_notes')->name('support.sticky-notes.update');
    Route::delete('/support/sticky-notes/{note}', [StickyNoteController::class, 'destroy'])->middleware('permission:use_sticky_notes')->name('support.sticky-notes.destroy');

    Route::get('/support/corrections', [CorrectionRequestController::class, 'index'])->middleware('permission:submit_correction_request')->name('support.corrections.index');
    Route::post('/support/corrections', [CorrectionRequestController::class, 'store'])->middleware('permission:submit_correction_request')->name('support.corrections.store');
    Route::put('/support/corrections/{correctionRequest}', [CorrectionRequestController::class, 'update'])->middleware('permission:manage_correction_requests')->name('support.corrections.update');

    Route::get('/support/contact', [SupportRequestController::class, 'index'])->middleware('permission:contact_support')->name('support.contact.index');
    Route::post('/support/contact', [SupportRequestController::class, 'store'])->middleware('permission:contact_support')->name('support.contact.store');
    Route::put('/support/contact/{supportRequest}', [SupportRequestController::class, 'update'])->middleware('permission:manage_support')->name('support.contact.update');
});
