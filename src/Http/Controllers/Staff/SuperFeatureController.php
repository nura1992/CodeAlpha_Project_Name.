<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\FeatureFlags;
use CodeAlpha\Core\FeatureRegistry;
use CodeAlpha\Core\Response;
use CodeAlpha\Core\Settings;
use CodeAlpha\Http\Controllers\Controller;

final class SuperFeatureController extends Controller
{
    public function index(): never
    {
        $this->view('staff/super/features', [
            'registry'=>FeatureRegistry::byCategory(),
            'states'=>FeatureFlags::allStates(),
            'lockdown'=> (bool)Settings::get('emergency_lockdown', false),
        ]);
    }
    public function toggle(): never
    {
        $key=(string)$this->request->input('feature');
        $enabled = $this->request->bool('enabled');
        // Also accept toggle without explicit enabled => flip
        if (!$this->request->has('enabled')) {
            $enabled = !FeatureFlags::isEnabled($key);
        }
        FeatureFlags::set($key,$enabled,Auth::id());
        AuditLog::record(Auth::id(),'feature.toggle','feature_flag',$key,['enabled'=>$enabled],$this->request);
        if($this->request->wantsJson()) Response::json(['feature'=>$key,'enabled'=>$enabled]);
        flash('success', FeatureFlags::label($key).' is now '.($enabled?'enabled':'disabled').'.');
        Response::redirect('/staff/super/features');
    }
    public function lockdown(): never
    {
        $enable = $this->request->bool('enable', true);
        if (!$this->request->has('enable')) $enable = !(bool)Settings::get('emergency_lockdown', false);
        Settings::set('emergency_lockdown',$enable,Auth::id(),true);
        AuditLog::record(Auth::id(),'feature.lockdown','setting','emergency_lockdown',['enabled'=>$enable],$this->request);
        if($this->request->wantsJson()) Response::json(['lockdown'=>$enable]);
        flash('success',$enable?'Emergency lockdown enabled. Only critical features remain.':'Lockdown lifted.');
        Response::redirect('/staff/super/features');
    }
}
