<?php

use App\Http\Controllers\CodeWorkspaceDownloadController;
use App\Http\Controllers\DatabaseExportController;
use App\Http\Controllers\DeploymentLogController;
use App\Http\Controllers\GithubAppHookController;
use App\Http\Controllers\GithubAppManifestController;
use App\Http\Controllers\GithubHookController;
use App\Http\Controllers\HeartbeatController;
use App\Http\Controllers\LiveServerHookController;
use App\Http\Controllers\MonitoringLiveController;
use App\Http\Controllers\PanelTaskController;
use App\Http\Controllers\PhpMyAdminController;
use App\Http\Controllers\TerminalSessionController;
use App\Http\Controllers\WebsiteFileController;
use App\Livewire\Account\Security as AccountSecurity;
use App\Livewire\Auth\Login;
use App\Livewire\Backups\Index as BackupsIndex;
use App\Livewire\Backups\ScheduleForm as BackupScheduleForm;
use App\Livewire\Backups\Storages as BackupStorages;
use App\Livewire\Dashboard;
use App\Livewire\Database\Index as DatabaseIndex;
use App\Livewire\Database\Show as DatabaseShow;
use App\Livewire\Database\Table as DatabaseTable;
use App\Livewire\Deployments\Index as DeploymentsIndex;
use App\Livewire\Domains\Index as DomainIndex;
use App\Livewire\Groups\Form as GroupForm;
use App\Livewire\Groups\Index as GroupIndex;
use App\Livewire\Groups\Show as GroupShow;
use App\Livewire\Monitoring\Index as MonitoringIndex;
use App\Livewire\Monitoring\Server as MonitoringServer;
use App\Livewire\Security\Access as SecurityAccess;
use App\Livewire\Security\PrivateKeys;
use App\Livewire\Server\Edit as ServerEdit;
use App\Livewire\Server\Index as ServerIndex;
use App\Livewire\Server\Nginx as ServerNginx;
use App\Livewire\Server\Ports as ServerPorts;
use App\Livewire\Server\Processes as ServerProcesses;
use App\Livewire\Settings\Cloudflare as SettingsCloudflare;
use App\Livewire\Settings\General;
use App\Livewire\Settings\Github;
use App\Livewire\Settings\Links;
use App\Livewire\Settings\Mcp as SettingsMcp;
use App\Livewire\Settings\Telegram;
use App\Livewire\Setup\CreateAdmin;
use App\Livewire\Setup\EnableTwoFactor;
use App\Livewire\Sources\GithubForm;
use App\Livewire\Sources\GithubShow;
use App\Livewire\Sources\Index as SourceIndex;
use App\Livewire\Tools\FileManager;
use App\Livewire\Tools\Terminal as ToolsTerminal;
use App\Livewire\Tunnels\Form as TunnelForm;
use App\Livewire\Tunnels\Index as TunnelIndex;
use App\Livewire\Tunnels\Show as TunnelShow;
use App\Livewire\Websites\Form as WebsiteForm;
use App\Livewire\Websites\Show as WebsiteShow;
use App\Livewire\Websites\VsCode as WebsiteVsCode;
use App\Livewire\Workspaces\Index as WorkspaceIndex;
use App\Livewire\Workspaces\Open as WorkspaceOpen;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/setup', CreateAdmin::class)->name('setup');
Route::get('/setup/two-factor', EnableTwoFactor::class)->name('setup.two-factor');
Route::get('/login', Login::class)->name('login');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

Route::get('/', Dashboard::class)->name('dashboard');

Route::get('/groups', GroupIndex::class)->name('groups.index');
Route::get('/groups/create', GroupForm::class)->name('groups.create');
Route::get('/groups/{group}/edit', GroupForm::class)->name('groups.edit');
Route::get('/groups/{group}/websites/create', WebsiteForm::class)->name('websites.create');
Route::get('/groups/{group}', GroupShow::class)->name('groups.show');

Route::get('/websites/{website}', WebsiteShow::class)->name('websites.show');
Route::get('/websites/{website}/files/download', WebsiteFileController::class)->name('websites.files.download');
Route::get('/websites/{website}/vscode', WebsiteVsCode::class)->name('websites.vscode');

// /code-restart/{token} (an editor that stopped while idle) and /code-share/{share} are in bootstrap/app.php.

Route::get('/workspaces', WorkspaceIndex::class)->name('workspaces.index');
Route::get('/workspaces/{workspace}/open', WorkspaceOpen::class)->name('workspaces.open');
Route::get('/workspaces/{workspace}/download', CodeWorkspaceDownloadController::class)->name('workspaces.download');

Route::get('/deployments', DeploymentsIndex::class)->name('deployments.index');
Route::get('/deployments/{deployment}/log', DeploymentLogController::class)->name('deployments.log');

Route::get('/monitoring', MonitoringIndex::class)->name('monitoring.index');
Route::get('/monitoring/server', MonitoringServer::class)->name('monitoring.server');
Route::get('/monitoring/server/live', [MonitoringLiveController::class, 'server'])->name('monitoring.server.live');
Route::get('/monitoring/websites/{website}/live', [MonitoringLiveController::class, 'website'])->name('monitoring.website.live');

Route::redirect('/backups', '/backups/databases');
Route::get('/backups/databases', BackupsIndex::class)->defaults('kind', 'database')->name('backups.databases');
Route::get('/backups/files', BackupsIndex::class)->defaults('kind', 'files')->name('backups.files');
Route::get('/backups/storages', BackupStorages::class)->name('backups.storages');
Route::get('/backups/schedules/create/{kind}', BackupScheduleForm::class)->whereIn('kind', ['database', 'files'])->name('backups.schedules.create');
Route::get('/backups/schedules/{schedule}/edit', BackupScheduleForm::class)->name('backups.schedules.edit');

Route::get('/files', FileManager::class)->name('files.index');
Route::get('/files/download', [WebsiteFileController::class, 'global'])->name('files.download');
Route::get('/tasks/{panelTask}/download', [PanelTaskController::class, 'download'])->name('tasks.download');
Route::get('/terminal', ToolsTerminal::class)->name('terminal.index');
Route::get('/terminal/sessions/{session}/output', [TerminalSessionController::class, 'output'])->name('terminal.sessions.output');
Route::post('/terminal/sessions/{session}/input', [TerminalSessionController::class, 'input'])->name('terminal.sessions.input');
Route::post('/terminal/sessions/{session}/stop', [TerminalSessionController::class, 'stop'])->name('terminal.sessions.stop');
Route::get('/database', DatabaseIndex::class)->name('database.index');
Route::post('/database/export', DatabaseExportController::class)->name('database.export');
Route::get('/database/{database}', DatabaseShow::class)->where('database', '[A-Za-z0-9_-]+')->name('database.show');
Route::get('/database/{database}/{table}', DatabaseTable::class)->where(['database' => '[A-Za-z0-9_-]+', 'table' => '[A-Za-z0-9_$-]+'])->name('database.table');
Route::get('/tools/phpmyadmin', PhpMyAdminController::class)->name('tools.phpmyadmin');

Route::get('/domains', DomainIndex::class)->name('domains.index');
Route::get('/tunnels', TunnelIndex::class)->name('tunnels.index');
Route::get('/tunnels/create', TunnelForm::class)->name('tunnels.create');
Route::get('/tunnels/{tunnel}', TunnelShow::class)->name('tunnels.show');

Route::get('/server', ServerIndex::class)->name('server.index');
Route::get('/server/nginx', ServerNginx::class)->name('server.nginx');
Route::get('/server/ports', ServerPorts::class)->name('server.ports');
Route::get('/server/processes', ServerProcesses::class)->name('server.processes');
Route::get('/server/{service}', ServerEdit::class)->name('server.edit');

Route::get('/settings/general', General::class)->name('settings.general');
Route::get('/settings/github', Github::class)->name('settings.github');
Route::get('/settings/telegram', Telegram::class)->name('settings.telegram');
Route::get('/settings/links', Links::class)->name('settings.links');
Route::get('/settings/mcp', SettingsMcp::class)->name('settings.mcp');
Route::get('/settings/cloudflare', SettingsCloudflare::class)->name('settings.cloudflare');
Route::get('/security/access', SecurityAccess::class)->name('security.access');
Route::get('/security/private-keys', PrivateKeys::class)->name('security.private-keys');
Route::get('/account/security', AccountSecurity::class)->name('account.security');

Route::get('/sources', SourceIndex::class)->name('sources.index');
Route::get('/sources/github/create', GithubForm::class)->name('sources.github.create');
Route::get('/sources/github/{githubApp}', GithubShow::class)->name('sources.github.show');

Route::match(['get', 'post', 'head'], '/hooks/heartbeat/{token}', HeartbeatController::class)
    ->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:60,1')
    ->name('hooks.heartbeat');

Route::post('/hooks/github/{website:slug}', GithubHookController::class)
    ->name('hooks.github');

Route::post('/hooks/live/{website:slug}/{action}', LiveServerHookController::class)
    ->whereIn('action', ['start', 'stop', 'auth'])
    ->middleware('throttle:30,1')
    ->name('hooks.live');

Route::post('/hooks/github-app/{githubApp:slug}', GithubAppHookController::class)
    ->name('hooks.github-app');

Route::get('/hooks/github-app/{githubApp:slug}/callback', [GithubAppManifestController::class, 'callback'])
    ->name('hooks.github-app.callback');

Route::get('/hooks/github-app/{githubApp:slug}/install', [GithubAppManifestController::class, 'install'])
    ->name('hooks.github-app.install');
