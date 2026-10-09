<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Database;
use App\Core\Eligibility;
use App\Core\Features;
use App\Core\Request;
use App\Core\Response;
use App\Core\SecureStorage;
use App\Core\Settings;
use App\Core\ApiException;
use App\Core\Validator;

final class ProfileController
{
    private const SENSITIVE = ['display_name', 'gender'];

    public function show(Request $r): array
    {
        $uid = $r->userId();
        return $this->ownProfile($uid);
    }

    /** Updates profile fields. Sensitive changes move an approved profile back to review. */
    public function save(Request $r): array
    {
        $uid = $r->userId();
        $profile = Database::one('SELECT * FROM profiles WHERE user_id = ?', [$uid]);
        $user = Database::one('SELECT gender FROM users WHERE id = ?', [$uid]);
        if ($profile === null || $user === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Profile not found.');
        }

        $changes = [];
        if ($r->input('display_name') !== null) {
            $changes['display_name'] = Validator::str($r->input('display_name'), 'display_name', 2, 60);
        }
        if ($r->input('gender') !== null) {
            $changes['gender'] = Validator::in($r->input('gender'), 'gender', ['male', 'female', 'other']);
        }
        if ($r->input('bio') !== null) {
            $changes['bio'] = Validator::str($r->input('bio'), 'bio', 0, 1000, false);
        }
        if ($r->input('city') !== null) {
            $changes['city'] = Validator::str($r->input('city'), 'city', 0, 100, false);
        }
        if ($r->input('state') !== null) {
            $changes['state'] = Validator::str($r->input('state'), 'state', 0, 100, false);
        }
        if ($r->input('relationship_intent') !== null) {
            $changes['relationship_intent'] = Validator::in($r->input('relationship_intent'), 'relationship_intent', ['serious', 'marriage', 'friendship', 'dating', 'unsure']);
        }
        if ($r->input('looking_for_gender') !== null) {
            $changes['looking_for_gender'] = Validator::in($r->input('looking_for_gender'), 'looking_for_gender', ['male', 'female', 'everyone']);
        }
        if ($r->input('age_pref_min') !== null || $r->input('age_pref_max') !== null) {
            $min = Validator::int($r->input('age_pref_min', $profile['age_pref_min']), 'age_pref_min', 18, 99);
            $max = Validator::int($r->input('age_pref_max', $profile['age_pref_max']), 'age_pref_max', 18, 99);
            if ($min > $max) {
                Validator::fail('age_pref_min', 'Minimum age cannot be greater than maximum age.');
            }
            $changes['age_pref_min'] = $min;
            $changes['age_pref_max'] = $max;
        }

        $interests = $r->input('interests');
        $interestNames = null;
        if (is_array($interests)) {
            if (count($interests) > 15) {
                Validator::fail('interests', 'Choose up to 15 interests.');
            }
            $interestNames = [];
            foreach ($interests as $name) {
                $clean = strtolower(Validator::str($name, 'interests', 2, 40));
                $interestNames[$clean] = $clean;
            }
        }

        // Sensitive change detection: moves an approved profile back into review.
        $sensitive = false;
        foreach (self::SENSITIVE as $field) {
            if (array_key_exists($field, $changes) && $changes[$field] !== ($field === 'gender' ? $user['gender'] : $profile[$field])) {
                $sensitive = true;
            }
        }

        $profileSets = [];
        $params = [];
        foreach ($changes as $col => $value) {
            if ($col === 'gender') {
                continue;
            }
            $profileSets[] = "{$col} = ?";
            $params[] = $value;
        }
        if ($sensitive && $profile['approval_status'] === 'approved') {
            $profileSets[] = "approval_status = 'pending', reverification_required = 1";
            Audit::log($uid, 'profile.sensitive_change', 'profile', $uid, null, null, ['fields' => array_keys(array_intersect_key($changes, array_flip(self::SENSITIVE)))]);
        }

        Database::transaction(function () use ($uid, $changes, $profileSets, $params, $interestNames, $sensitive): void {
            if (isset($changes['gender'])) {
                Database::exec('UPDATE users SET gender = ? WHERE id = ?', [$changes['gender'], $uid]);
            }
            if ($profileSets !== []) {
                Database::exec('UPDATE profiles SET ' . implode(', ', $profileSets) . ' WHERE user_id = ?', [...$params, $uid]);
            }
            if ($interestNames !== null) {
                Database::exec('DELETE FROM profile_interests WHERE user_id = ?', [$uid]);
                foreach ($interestNames as $name) {
                    Database::exec('INSERT IGNORE INTO interests (name) VALUES (?)', [$name]);
                    Database::exec(
                        'INSERT INTO profile_interests (user_id, interest_id) SELECT ?, id FROM interests WHERE name = ?',
                        [$uid, $name]
                    );
                }
            }
        });

        return $this->ownProfile($uid) + ['re_review_required' => $sensitive && ($profile['approval_status'] === 'approved')];
    }

    /** Another member's profile, visible only when the server-side matchmaking rules allow it. */
    public function publicView(Request $r): array
    {
        Features::require('matchmaking.discovery');
        $viewerId = $r->userId();
        $targetId = (int) $r->param('id');
        if ($targetId === $viewerId) {
            return $this->ownProfile($viewerId);
        }
        $target = Eligibility::assertCanView($viewerId, $targetId);
        $profile = Database::one(
            'SELECT p.display_name, p.bio, p.city, p.country, p.relationship_intent FROM profiles p WHERE p.user_id = ?',
            [$targetId]
        );
        $photos = Database::all(
            "SELECT id, is_primary FROM profile_photos WHERE user_id = ? AND moderation_status = 'approved' AND deleted_at IS NULL ORDER BY is_primary DESC, id ASC",
            [$targetId]
        );
        return [
            'profile' => $profile + [
                'age' => Eligibility::ageOf((string) $target['date_of_birth']),
                'gender' => $target['gender'],
                'interests' => $this->interestsOf($targetId),
            ],
            'photos' => array_map(static fn($p) => ['id' => (int) $p['id'], 'url' => '/api/media/photos/' . $p['id']], $photos),
        ];
    }

    public function uploadPhoto(Request $r): array
    {
        Features::require('account.verification');
        $uid = $r->userId();
        $profile = Database::one('SELECT approval_status FROM profiles WHERE user_id = ?', [$uid]);
        if ($profile === null || $profile['approval_status'] === 'suspended') {
            throw new ApiException(403, 'PROFILE_SUSPENDED', 'Your profile cannot be edited right now.');
        }
        $max = Settings::getInt('profiles.max_photos', 6);
        $count = (int) Database::value('SELECT COUNT(*) FROM profile_photos WHERE user_id = ? AND deleted_at IS NULL', [$uid]);
        if ($count >= $max) {
            throw new ApiException(422, 'PHOTO_LIMIT', "You can upload up to {$max} photos.");
        }
        $file = SecureStorage::inspectUpload($r->files['photo'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], 8 * 1048576, 'photo');
        if (@getimagesize($file['tmp']) === false) {
            Validator::fail('photo', 'The uploaded file is not a valid image.');
        }
        $key = SecureStorage::putFile('photos', $file['tmp']);
        $id = Database::insert(
            "INSERT INTO profile_photos (user_id, storage_key, mime, size_bytes, sha256, is_primary)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$uid, $key, $file['mime'], $file['size'], $file['sha256'], $count === 0 ? 1 : 0]
        );
        Audit::log($uid, 'photo.uploaded', 'profile_photo', $id);
        Response::status(201);
        return ['id' => $id, 'moderation_status' => 'pending', 'message' => 'Photo received and awaiting review.'];
    }

    public function deletePhoto(Request $r): array
    {
        $uid = $r->userId();
        $photo = Database::one('SELECT * FROM profile_photos WHERE id = ? AND user_id = ? AND deleted_at IS NULL', [(int) $r->param('id'), $uid]);
        if ($photo === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Photo not found.');
        }
        Database::exec('UPDATE profile_photos SET deleted_at = NOW(), is_primary = 0 WHERE id = ?', [$photo['id']]);
        SecureStorage::secureDelete('photos', $photo['storage_key']);
        return ['ok' => true];
    }

    public function setPrimaryPhoto(Request $r): array
    {
        $uid = $r->userId();
        $id = (int) $r->param('id');
        Database::transaction(static function () use ($uid, $id): void {
            $ok = Database::value("SELECT 1 FROM profile_photos WHERE id = ? AND user_id = ? AND moderation_status = 'approved' AND deleted_at IS NULL", [$id, $uid]);
            if (!$ok) {
                throw new ApiException(422, 'PHOTO_NOT_APPROVED', 'Only approved photos can be set as primary.');
            }
            Database::exec('UPDATE profile_photos SET is_primary = 0 WHERE user_id = ?', [$uid]);
            Database::exec('UPDATE profile_photos SET is_primary = 1 WHERE id = ?', [$id]);
        });
        return ['ok' => true];
    }

    /** Streams a photo only to its owner, or to a viewer the matchmaking rules allow (approved photos only). */
    public function photoMedia(Request $r): ?array
    {
        $viewer = $r->userId();
        $photo = Database::one('SELECT * FROM profile_photos WHERE id = ? AND deleted_at IS NULL', [(int) $r->param('id')]);
        if ($photo === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Photo not found.');
        }
        if ((int) $photo['user_id'] !== $viewer) {
            if ($photo['moderation_status'] !== 'approved') {
                throw new ApiException(404, 'NOT_FOUND', 'Photo not found.');
            }
            Eligibility::assertCanView($viewer, (int) $photo['user_id']);
        }
        Response::binary(SecureStorage::get('photos', $photo['storage_key']), $photo['mime'], 'inline', 'photo.' . SecureStorage::mimeToExt($photo['mime']));
        return null;
    }

    private function interestsOf(int $uid): array
    {
        return array_column(Database::all(
            'SELECT i.name FROM profile_interests pi JOIN interests i ON i.id = pi.interest_id WHERE pi.user_id = ? ORDER BY i.name',
            [$uid]
        ), 'name');
    }

    private function ownProfile(int $uid): array
    {
        $profile = Database::one(
            'SELECT u.username, u.gender, u.date_of_birth, p.* FROM users u JOIN profiles p ON p.user_id = u.id WHERE u.id = ?',
            [$uid]
        ) ?? [];
        $photos = Database::all(
            'SELECT id, is_primary, moderation_status, rejection_reason, created_at FROM profile_photos WHERE user_id = ? AND deleted_at IS NULL ORDER BY id',
            [$uid]
        );
        unset($profile['user_id']);
        $profile['age'] = !empty($profile['date_of_birth']) ? Eligibility::ageOf((string) $profile['date_of_birth']) : null;
        unset($profile['date_of_birth']);
        return [
            'profile' => $profile + ['interests' => $this->interestsOf($uid)],
            'photos' => array_map(static fn($p) => $p + ['url' => '/api/media/photos/' . $p['id']], $photos),
            'approval_status' => $profile['approval_status'] ?? 'draft',
            'discovery_eligible' => Eligibility::isApproved(Eligibility::memberRow($uid)),
        ];
    }
}
