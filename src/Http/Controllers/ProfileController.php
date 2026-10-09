<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\AuditLog;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Core\Uploader;
use CodeAlpha\Core\Validator;
use CodeAlpha\Services\VerificationService;

final class ProfileController extends Controller
{
    public function apiMe(): never
    {
        $user = $this->user();
        $profile = Database::one('SELECT * FROM profiles WHERE user_id = :id', ['id' => $user['id']]);
        $photos = Database::all("SELECT id, path, mime, is_avatar, position, moderation_status FROM profile_photos WHERE user_id = :id ORDER BY is_avatar DESC, position ASC", ['id' => $user['id']]);
        foreach ($photos as &$p) { $p['url'] = '/uploads/' . $p['path']; } unset($p);
        $this->ok([
            'user' => [
                'id' => (int)$user['id'], 'uuid' => $user['uuid'], 'email' => $user['email'], 'phone' => $user['phone'],
                'gender' => $user['gender'], 'birthdate' => $user['birthdate'], 'status' => $user['status'],
            ],
            'profile' => $profile ? [
                'display_name' => $profile['display_name'], 'bio' => $profile['bio'],
                'interests' => json_decode((string)($profile['interests']??''), true)?:[],
                'location_country' => $profile['location_country'], 'location_city' => $profile['location_city'],
                'latitude' => $profile['latitude'], 'longitude' => $profile['longitude'],
                'relationship_intention' => $profile['relationship_intention'],
                'min_age' => (int)$profile['min_age'], 'max_age' => (int)$profile['max_age'],
                'height_cm' => $profile['height_cm'], 'education' => $profile['education'],
                'occupation' => $profile['occupation'], 'religion' => $profile['religion'],
                'smoking' => $profile['smoking'], 'drinking' => $profile['drinking'],
                'verification_status' => $profile['verification_status'], 'verified_at' => $profile['verified_at'],
                'discoverable' => (bool)$profile['discoverable'], 'hidden' => (bool)$profile['hidden'],
                'profile_score' => (int)$profile['profile_score'],
                'privacy' => json_decode((string)($profile['privacy']??''), true)?:[],
                'notification_settings' => json_decode((string)($profile['notification_settings']??''), true)?:[],
            ] : null,
            'photos' => $photos,
        ]);
    }

    public function apiUpdate(): never
    {
        $user = $this->user();
        $data = $this->request->all();
        $allowed = ['display_name','bio','interests','location_country','location_city','latitude','longitude','relationship_intention','min_age','max_age','height_cm','education','occupation','religion','smoking','drinking','privacy','notification_settings','hidden'];
        $update = [];
        foreach ($allowed as $k) { if (array_key_exists($k, $data)) $update[$k] = $data[$k]; }

        if (isset($update['display_name'])) {
            $v = Validator::make(['display_name'=>$update['display_name']], ['display_name'=>['required','min:2','max:100']]);
            if ($v->fails()) $this->error($v->firstError(), 422);
            $update['display_name'] = trim((string)$update['display_name']);
        }
        if (isset($update['bio']) && mb_strlen((string)$update['bio']) > 2000) $this->error('Bio is too long (max 2000).',422);
        if (isset($update['interests'])) {
            $interests = is_array($update['interests']) ? $update['interests'] : explode(',', (string)$update['interests']);
            $interests = array_values(array_filter(array_map('trim', $interests)));
            $interests = array_slice($interests, 0, 20);
            $update['interests'] = json_encode($interests);
        } else { unset($update['interests']); }

        if (isset($update['relationship_intention']) && !in_array($update['relationship_intention'], ['casual','relationship','marriage','friendship',''], true)) unset($update['relationship_intention']);
        if (isset($update['smoking']) && !in_array($update['smoking'], ['no','socially','yes',''], true)) unset($update['smoking']);
        if (isset($update['drinking']) && !in_array($update['drinking'], ['no','socially','yes',''], true)) unset($update['drinking']);
        if (isset($update['latitude'])) $update['latitude'] = $update['latitude']===''?null:(float)$update['latitude'];
        if (isset($update['longitude'])) $update['longitude'] = $update['longitude']===''?null:(float)$update['longitude'];
        if (isset($update['min_age'])) $update['min_age'] = max(18, min(99, (int)$update['min_age']));
        if (isset($update['max_age'])) $update['max_age'] = max(18, min(99, (int)$update['max_age']));
        if (isset($update['height_cm'])) $update['height_cm'] = $update['height_cm']===''?null:(int)$update['height_cm'];
        if (isset($update['privacy']) && is_array($update['privacy'])) $update['privacy'] = json_encode($update['privacy']);
        if (isset($update['notification_settings']) && is_array($update['notification_settings'])) $update['notification_settings'] = json_encode($update['notification_settings']);
        if (isset($update['hidden'])) $update['hidden'] = !empty($update['hidden']) ? 1 : 0;

        // Sensitive changes that trigger re-review if already approved
        $sensitive = ['display_name','location_city','location_country'];
        $profile = Database::one('SELECT * FROM profiles WHERE user_id = :id', ['id'=>$user['id']]);
        $needsReReview = $profile && $profile['verification_status']==='approved' && count(array_intersect(array_keys($update), $sensitive))>0;
        if ($needsReReview) {
            // Keep discoverability but flag for admin re-check via audit log
            AuditLog::record((int)$user['id'], 'profile.sensitive_change', 'profile', (string)$user['id'], ['fields'=>array_keys($update)], $this->request);
        }

        if ($update) {
            $update['updated_at'] = date('Y-m-d H:i:s');
            Database::update('profiles', $update, 'user_id = :id', ['id'=>$user['id']]);
            VerificationService::recomputeScore((int)$user['id']);
        }
        AuditLog::record((int)$user['id'], 'profile.update', 'profile', (string)$user['id'], ['fields'=>array_keys($update)], $this->request);
        $this->ok(['updated'=>true]);
    }

    public function apiUploadPhoto(): never
    {
        $user = $this->user();
        $file = $this->request->file('photo');
        if (!$file) $this->error('Photo file is required.',422);
        $count = (int)Database::scalar('SELECT COUNT(*) FROM profile_photos WHERE user_id = :id', ['id'=>$user['id']]);
        if ($count >= 6) $this->error('You can upload at most 6 photos.',422);
        $stored = Uploader::storePublic($file, 'image', 'profile');
        $isAvatar = $count===0 ? 1 : 0;
        $id = (int)Database::insert('profile_photos', [
            'user_id'=> $user['id'],
            'path'=> substr($stored['path'], strlen('/uploads/')),
            'mime'=> $stored['mime'],
            'size_bytes'=> $stored['size'],
            'is_avatar'=> $isAvatar,
            'position'=> $count,
            'moderation_status'=>'approved',
            'created_at'=> date('Y-m-d H:i:s'),
        ]);
        VerificationService::recomputeScore((int)$user['id']);
        AuditLog::record((int)$user['id'], 'profile.photo_upload', 'profile_photo', (string)$id, [], $this->request);
        $this->ok(['photo'=>['id'=>$id,'url'=>$stored['path'],'is_avatar'=>(bool)$isAvatar]],201);
    }

    public function apiDeletePhoto(array $params): never
    {
        $user = $this->user();
        $id = (int)$params['id'];
        $photo = Database::one('SELECT * FROM profile_photos WHERE id = :id AND user_id = :uid', ['id'=>$id,'uid'=>$user['id']]);
        if (!$photo) $this->error('Photo not found.',404);
        $fs = rtrim((string)config('storage.uploads'),'/').'/'.$photo['path'];
        @unlink($fs);
        Database::delete('profile_photos','id = :id',['id'=>$id]);
        // If avatar deleted, promote next
        if ((int)$photo['is_avatar']===1) {
            $next = Database::one('SELECT id FROM profile_photos WHERE user_id = :uid ORDER BY position ASC LIMIT 1', ['uid'=>$user['id']]);
            if ($next) Database::update('profile_photos',['is_avatar'=>1],'id = :id',['id'=>$next['id']]);
        }
        VerificationService::recomputeScore((int)$user['id']);
        $this->ok(['deleted'=>true]);
    }

    public function apiSetAvatar(array $params): never
    {
        $user = $this->user();
        $id = (int)$params['id'];
        $photo = Database::one('SELECT * FROM profile_photos WHERE id = :id AND user_id = :uid', ['id'=>$id,'uid'=>$user['id']]);
        if (!$photo) $this->error('Photo not found.',404);
        Database::query('UPDATE profile_photos SET is_avatar = 0 WHERE user_id = :uid', ['uid'=>$user['id']]);
        Database::update('profile_photos',['is_avatar'=>1],'id = :id',['id'=>$id]);
        $this->ok(['avatar'=>true]);
    }

    public function apiDeleteAccount(): never
    {
        $user = $this->user();
        $confirm = $this->request->string('confirm');
        if ($confirm !== 'DELETE' && $confirm !== (string)$user['email']) {
            $this->error('Please confirm account deletion by typing DELETE.',422);
        }
        AuditLog::record((int)$user['id'], 'account.delete', 'user', (string)$user['id'], [], $this->request);
        Database::update('users',['status'=>'deleted','deleted_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')],'id = :id',['id'=>$user['id']]);
        Database::update('profiles',['hidden'=>1,'discoverable'=>0,'updated_at'=>date('Y-m-d H:i:s')],'user_id = :id',['id'=>$user['id']]);
        Auth::logout();
        $this->ok(['deleted'=>true]);
    }
}
