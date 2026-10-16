<?php

namespace MedCore\Modules\Admin;

use MedCore\Core\Auth;
use MedCore\Core\Authorization;
use MedCore\Core\Database;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Core\Session;
use MedCore\Services\AuditLogger;

/**
 * Platform settings (super admin only).
 */
final class SettingsController
{
    private const EDITABLE = ['platform_name', 'support_email', 'support_phone', 'maintenance_message'];

    public function index(): void
    {
        Authorization::authorize('settings.manage');
        $rows = Database::select('SELECT * FROM system_settings');
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = $row['value'];
        }
        Response::view('admin/settings/index', [
            'title'    => 'Platform settings',
            'settings' => $settings,
            'editable' => self::EDITABLE,
        ]);
    }

    public function update(Request $request): void
    {
        Authorization::authorize('settings.manage');
        foreach (self::EDITABLE as $key) {
            $value = trim((string) $request->input($key, ''));
            Database::execute('UPDATE system_settings SET value = ?, updated_at = ' . Database::nowExpr() . ' WHERE key = ?', [$value, $key]);
        }
        AuditLogger::log(Auth::id(), 'settings.updated', 'system_setting', null, ['keys' => self::EDITABLE], $request);
        Session::flash('success', 'Settings saved.');
        Response::redirect('/admin/settings');
    }
}
