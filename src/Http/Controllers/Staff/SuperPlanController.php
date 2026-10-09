<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;

final class SuperPlanController extends Controller
{
    public function index(): never
    {
        $plans = Database::all('SELECT * FROM subscription_plans ORDER BY position ASC, id ASC');
        $this->view('staff/super/plans', ['plans'=>$plans]);
    }
    public function store(): never
    {
        $p=$this->request->all();
        $slug=strtolower(preg_replace('/[^a-z0-9_]+/','_', trim((string)($p['slug']??$p['name']??''))));
        $features = isset($p['features']) ? (is_array($p['features'])?$p['features']:explode(',',(string)$p['features'])) : [];
        $features = array_values(array_filter(array_map('trim',$features)));
        $id=(int)Database::insert('subscription_plans',['name'=>substr(trim((string)$p['name']),0,100),'slug'=>$slug,'description'=>substr((string)($p['description']??''),0,1000)?:null,'price'=>(float)($p['price']??0),'currency'=>substr((string)($p['currency']??'NGN'),0,3),'interval'=>in_array($p['interval']??'',['month','year'],true)?$p['interval']:'month','features'=>json_encode($features),'is_active'=>!empty($p['is_active'])?1:1,'position'=>(int)($p['position']??0),'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        AuditLog::record(Auth::id(),'plan.create','subscription_plan',(string)$id,[],$this->request);
        if($this->request->wantsJson()) Response::json(['plan_id'=>$id],201);
        flash('success','Plan created.'); Response::redirect('/staff/super/plans');
    }
    public function update(array $params): never
    {
        $id=(int)$params['id'];
        $p=$this->request->all();
        $data=[];
        if(isset($p['name'])) $data['name']=substr(trim((string)$p['name']),0,100);
        if(isset($p['price'])) $data['price']=(float)$p['price'];
        if(isset($p['currency'])) $data['currency']=substr((string)$p['currency'],0,3);
        if(isset($p['interval']) && in_array($p['interval'],['month','year'],true)) $data['interval']=$p['interval'];
        if(isset($p['description'])) $data['description']=substr((string)$p['description'],0,1000)?:null;
        if(isset($p['features'])){ $f=is_array($p['features'])?$p['features']:explode(',',(string)$p['features']); $data['features']=json_encode(array_values(array_filter(array_map('trim',$f)))); }
        if(isset($p['is_active'])) $data['is_active']=!empty($p['is_active'])?1:0;
        if(isset($p['position'])) $data['position']=(int)$p['position'];
        if($data){ $data['updated_at']=date('Y-m-d H:i:s'); Database::update('subscription_plans',$data,'id=:id',['id'=>$id]); AuditLog::record(Auth::id(),'plan.update','subscription_plan',(string)$id,[],$this->request); }
        if($this->request->wantsJson()) Response::json(['updated'=>true]);
        flash('success','Plan updated.'); Response::redirect('/staff/super/plans');
    }
    public function toggle(array $params): never
    {
        $id=(int)$params['id'];
        $plan=Database::one('SELECT is_active FROM subscription_plans WHERE id=:id',['id'=>$id]);
        if(!$plan) Response::notFound();
        $new = (int)$plan['is_active']?0:1;
        if($this->request->has('is_active')) $new = $this->request->bool('is_active')?1:0;
        Database::update('subscription_plans',['is_active'=>$new,'updated_at'=>date('Y-m-d H:i:s')],'id=:id',['id'=>$id]);
        AuditLog::record(Auth::id(),'plan.toggle','subscription_plan',(string)$id,['is_active'=>$new],$this->request);
        if($this->request->wantsJson()) Response::json(['is_active'=>(bool)$new]);
        Response::redirect('/staff/super/plans');
    }
}
