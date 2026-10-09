<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Core\Settings;
use CodeAlpha\Http\Controllers\Controller;

final class SuperSettingsController extends Controller
{
    private array $editable = [
        'site_name'=>['label'=>'Site name','protected'=>false],
        'site_tagline'=>['label'=>'Tagline','protected'=>false],
        'community_guidelines'=>['label'=>'Community guidelines','protected'=>false],
        'terms_of_service'=>['label'=>'Terms of Service','protected'=>false],
        'privacy_policy'=>['label'=>'Privacy Policy','protected'=>false],
        'maintenance_message'=>['label'=>'Maintenance message','protected'=>false],
        'support_email'=>['label'=>'Support email','protected'=>false],
        'vapid_public'=>['label'=>'VAPID public key','protected'=>true],
        'vapid_private'=>['label'=>'VAPID private key','protected'=>true],
        'turn_server'=>['label'=>'TURN server','protected'=>true],
        'turn_username'=>['label'=>'TURN username','protected'=>true],
        'turn_password'=>['label'=>'TURN password','protected'=>true],
    ];
    public function index(): never
    {
        $rows = Settings::rows();
        $byKey=[]; foreach($rows as $r){ $byKey[$r['setting_key']]=$r; }
        $this->view('staff/super/settings', ['editable'=>$this->editable,'settings'=>$byKey]);
    }
    public function update(): never
    {
        foreach ($this->editable as $key=>$meta) {
            if ($this->request->has($key)) {
                $val = (string)$this->request->input($key);
                Settings::set($key, $val, Auth::id(), (bool)$meta['protected']);
                AuditLog::record(Auth::id(),'setting.update','setting',$key,[],$this->request);
            }
        }
        // Handle booleans not in editable
        if($this->request->has('emergency_lockdown')){
            Settings::set('emergency_lockdown', $this->request->bool('emergency_lockdown'), Auth::id(), true);
        }
        flash('success','Settings saved.');
        Response::redirect('/staff/super/settings');
    }
}
