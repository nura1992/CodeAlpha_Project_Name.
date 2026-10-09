<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Core\Eligibility;
use App\Core\Features;
use App\Core\Otp;
use App\Core\Rbac;
use App\Core\Request;
use App\Core\SecureStorage;
use App\Core\Validator;
use App\Core\ApiException;
use App\Core\Response;

final class AuthController
{
    public function register(Request $r): array
    {
        Features::require('account.registration');
        \App\Core\RateLimiter::hit('register:ip:' . $r->ip, 10, 3600);

        $channel = Validator::in($r->input('channel'), 'channel', ['email', 'phone']);
        $username = Validator::username($r->input('username'));
        $password = Validator::password($r->input('password'));
        $displayName = Validator::str($r->input('display_name'), 'display_name', 2, 60);
        $gender = Validator::in($r->input('gender'), 'gender', ['male', 'female', 'other']);
        $dob = Validator::date($r->input('date_of_birth'), 'date_of_birth');
        Eligibility::assertAdult($dob); // Age eligibility is always enforced.
        $defaultLooking = ['male' => 'female', 'female' => 'male', 'other' => 'everyone'][$gender];
        $looking = Validator::in($r->input('looking_for_gender', $defaultLooking), 'looking_for_gender', ['male', 'female', 'everyone']);
        $intent = Validator::in($r->input('relationship_intent', 'unsure'), 'relationship_intent', ['serious', 'marriage', 'friendship', 'dating', 'unsure']);

        $email = null;
        $phone = null;
        if ($channel === 'email') {
            $email = Validator::email($r->input('email'));
            $destination = $email;
        } else {
            $phone = Validator::phone($r->input('phone'));
            $destination = $phone;
        }

        if (Database::value('SELECT 1 FROM users WHERE username = ?', [$username])) {
            throw new ApiException(409, 'USERNAME_TAKEN', 'That username is already taken.', ['field' => 'username']);
        }
        if (Database::value('SELECT 1 FROM users WHERE ' . ($channel === 'email' ? 'email' : 'phone') . ' = ?', [$destination])) {
            throw new ApiException(409, 'ACCOUNT_EXISTS', 'An account already exists for those details. Try signing in instead.');
        }

        $userId = Database::transaction(static function () use ($username, $email, $phone, $password, $gender, $dob, $displayName, $looking, $intent): int {
            $uid = Database::insert(
                "INSERT INTO users (username, email, phone, password_hash, gender, date_of_birth, account_type, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'member', 'unverified')",
                [$username, $email, $phone, Auth::hashPassword($password), $gender, $dob->format('Y-m-d')]
            );
            Database::exec('INSERT INTO user_settings (user_id) VALUES (?)', [$uid]);
            Database::exec(
                'INSERT INTO profiles (user_id, display_name, looking_for_gender, relationship_intent, approval_status)
                 VALUES (?, ?, ?, ?, ' . "'draft')",
                [$uid, $displayName, $looking, $intent]
            );
            return $uid;
        });

        Otp::issue($destination, $channel, 'register', $userId);
        return ['user_id' => $userId, 'channel' => $channel, 'message' => 'We sent a 6-digit verification code.'];
    }

    public function resendOtp(Request $r): array
    {
        $channel = Validator::in($r->input('channel'), 'channel', ['email', 'phone']);
        $destination = $channel === 'email' ? Validator::email($r->input('destination')) : Validator::phone($r->input('destination'));
        $user = Database::one('SELECT id FROM users WHERE ' . ($channel === 'email' ? 'email' : 'phone') . " = ? AND status = 'unverified'", [$destination]);
        if ($user !== null) {
            Otp::issue($destination, $channel, 'register', (int) $user['id']);
        }
        return ['message' => 'If that account is awaiting verification, a new code has been sent.'];
    }

    public function verifyOtp(Request $r): array
    {
        Features::require('account.registration');
        $channel = Validator::in($r->input('channel'), 'channel', ['email', 'phone']);
        $destination = $channel === 'email' ? Validator::email($r->input('destination')) : Validator::phone($r->input('destination'));
        $code = Validator::str($r->input('code'), 'code', 6, 6);
        $otp = Otp::verify($destination, 'register', $code);
        if ($otp === null || empty($otp['user_id'])) {
            throw new ApiException(422, 'INVALID_OTP', 'That code is invalid or has expired.', ['field' => 'code']);
        }
        $column = $channel === 'email' ? 'email_verified_at' : 'phone_verified_at';
        Database::exec("UPDATE users SET status = 'active', {$column} = NOW() WHERE id = ? AND status = 'unverified'", [$otp['user_id']]);
        $session = Auth::startSession((int) $otp['user_id'], $r);
        return $this->sessionPayload((int) $otp['user_id'], $session['csrf_token']);
    }

    public function login(Request $r): array
    {
        $identifier = Validator::str($r->input('identifier'), 'identifier', 3, 190);
        $password = (string) $r->input('password', '');
        if ($password === '') {
            Validator::fail('password', 'Enter your password.');
        }
        $session = Auth::login($identifier, $password, $r);
        return $this->sessionPayload((int) $session['user_id'], $session['csrf_token']);
    }

    public function logout(Request $r): array
    {
        Auth::logout($r);
        return ['ok' => true];
    }

    public function me(Request $r): array
    {
        $user = Auth::requireUser($r);
        return $this->sessionPayload((int) $user['id'], (string) $user['csrf_token']);
    }

    public function forgotPassword(Request $r): array
    {
        $channel = Validator::in($r->input('channel'), 'channel', ['email', 'phone']);
        $destination = $channel === 'email' ? Validator::email($r->input('destination')) : Validator::phone($r->input('destination'));
        $user = Database::one('SELECT id FROM users WHERE ' . ($channel === 'email' ? 'email' : 'phone') . " = ? AND status = 'active'", [$destination]);
        if ($user !== null) {
            Otp::issue($destination, $channel, 'reset', (int) $user['id']);
        }
        // Same response whether or not the account exists (no enumeration).
        return ['message' => 'If an account exists for that contact, a reset code has been sent.'];
    }

    public function resetPassword(Request $r): array
    {
        $channel = Validator::in($r->input('channel'), 'channel', ['email', 'phone']);
        $destination = $channel === 'email' ? Validator::email($r->input('destination')) : Validator::phone($r->input('destination'));
        $code = Validator::str($r->input('code'), 'code', 6, 6);
        $password = Validator::password($r->input('password'));
        $otp = Otp::verify($destination, 'reset', $code);
        if ($otp === null || empty($otp['user_id'])) {
            throw new ApiException(422, 'INVALID_OTP', 'That code is invalid or has expired.', ['field' => 'code']);
        }
        Database::exec(
            'UPDATE users SET password_hash = ?, must_change_password = 0, failed_logins = 0, locked_until = NULL WHERE id = ?',
            [Auth::hashPassword($password), $otp['user_id']]
        );
        Auth::revokeAll((int) $otp['user_id']);
        Audit::log((int) $otp['user_id'], 'auth.password_reset', 'user', (int) $otp['user_id']);
        return ['message' => 'Your password has been reset. Please sign in.'];
    }

    public function changePassword(Request $r): array
    {
        $uid = $r->userId();
        $current = (string) $r->input('current_password', '');
        $new = Validator::password($r->input('new_password'));
        $hash = Database::value('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!is_string($hash) || !password_verify($current, $hash)) {
            throw new ApiException(422, 'INVALID_PASSWORD', 'Your current password is not correct.', ['field' => 'current_password']);
        }
        Database::exec('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [Auth::hashPassword($new), $uid]);
        Database::exec('UPDATE sessions SET revoked_at = NOW() WHERE user_id = ? AND id <> ?', [$uid, $r->user['session_id'] ?? 0]);
        return ['message' => 'Password updated. Other sessions have been signed out.'];
    }

    public function updatePrivacy(Request $r): array
    {
        $uid = $r->userId();
        $bool = static fn($k) => Validator::boolean($r->input($k)) ? 1 : 0;
        Database::exec(
            'UPDATE user_settings SET notify_messages = ?, notify_calls = ?, notify_community = ?, allow_calls = ? WHERE user_id = ?',
            [$bool('notify_messages'), $bool('notify_calls'), $bool('notify_community'), $bool('allow_calls'), $uid]
        );
        return $this->settings($uid);
    }

    public function settingsShow(Request $r): array
    {
        return $this->settings($r->userId());
    }

    /** Account deletion: anonymises personal data, removes media and revokes sessions. */
    public function deleteAccount(Request $r): array
    {
        $uid = $r->userId();
        $password = (string) $r->input('password', '');
        $hash = Database::value('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!is_string($hash) || !password_verify($password, $hash)) {
            throw new ApiException(422, 'INVALID_PASSWORD', 'Enter your password to confirm deletion.', ['field' => 'password']);
        }
        $photos = Database::all('SELECT storage_key FROM profile_photos WHERE user_id = ?', [$uid]);
        $verification = Database::all('SELECT storage_key FROM verification_requests WHERE user_id = ? AND storage_key IS NOT NULL AND media_deleted_at IS NULL', [$uid]);
        Database::transaction(static function () use ($uid): void {
            Database::exec(
                "UPDATE users SET status = 'deleted', deleted_at = NOW(), username = NULL, email = NULL, phone = NULL,
                 password_hash = NULL WHERE id = ?",
                [$uid]
            );
            Database::exec(
                "UPDATE profiles SET display_name = 'Deleted member', bio = NULL, approval_status = 'suspended' WHERE user_id = ?",
                [$uid]
            );
            Database::exec('UPDATE profile_photos SET deleted_at = NOW() WHERE user_id = ?', [$uid]);
            Database::exec("UPDATE community_posts SET status = 'removed', deleted_at = NOW() WHERE author_id = ?", [$uid]);
            Database::exec("UPDATE matches SET status = 'unmatched', ended_at = NOW(), ended_by = ? WHERE (user_low_id = ? OR user_high_id = ?) AND status = 'active'", [$uid, $uid, $uid]);
        });
        foreach ($photos as $p) {
            SecureStorage::secureDelete('photos', $p['storage_key']);
        }
        foreach ($verification as $v) {
            SecureStorage::secureDelete('verification', $v['storage_key']);
            Database::exec('UPDATE verification_requests SET media_deleted_at = NOW(), storage_key = NULL WHERE storage_key = ?', [$v['storage_key']]);
        }
        Auth::revokeAll($uid);
        Audit::log($uid, 'account.deleted', 'user', $uid);
        Auth::clearCookie();
        return ['message' => 'Your account has been deleted.'];
    }

    private function settings(int $uid): array
    {
        return Database::one('SELECT notify_messages, notify_calls, notify_community, allow_calls FROM user_settings WHERE user_id = ?', [$uid]) ?? [];
    }

    /** Public session payload: identity, role/permission set, module states and profile status. */
    public function sessionPayload(int $uid, string $csrf): array
    {
        $user = Database::one(
            'SELECT id, username, gender, account_type, status, must_change_password, date_of_birth FROM users WHERE id = ?',
            [$uid]
        );
        $profile = Database::one('SELECT display_name, approval_status, reverification_required, looking_for_gender FROM profiles WHERE user_id = ?', [$uid]);
        $isStaff = ($user['account_type'] ?? '') === 'staff';
        $features = [];
        foreach (Features::map() as $key => $f) {
            $features[$key] = ['label' => $f['label'], 'group' => $f['group'], 'enabled' => $f['effective'], 'locked' => $f['locked']];
        }
        $age = !empty($user['date_of_birth']) ? Eligibility::ageOf((string) $user['date_of_birth']) : null;
        unset($user['date_of_birth']);
        return [
            'user' => $user + ['age' => $age],
            'csrf_token' => $csrf,
            'profile' => $profile,
            'settings' => $this->settings($uid),
            'permissions' => $isStaff ? Rbac::permissionsFor($uid) : [],
            'staff_roles' => $isStaff ? Database::all(
                'SELECT r.slug, r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$uid]) : [],
            'features' => $features,
        ];
    }
}
