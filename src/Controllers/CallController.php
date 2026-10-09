<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\ApiException;
use App\Core\Audit;
use App\Core\Database;
use App\Core\Eligibility;
use App\Core\Features;
use App\Core\Notifier;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;
use App\Core\Settings;
use App\Core\Signalling;
use App\Core\Env;
use App\Core\Validator;

/**
 * Call records and authorisation. Media flows peer-to-peer over WebRTC; this controller
 * decides WHO may call WHOM, records the lifecycle (ringing, accepted, missed, declined,
 * ended) and issues signed tickets for the WebSocket signalling service.
 */
final class CallController
{
    public function create(Request $r): array
    {
        $uid = $r->userId();
        $calleeId = Validator::int($r->input('user_id'), 'user_id', 1, PHP_INT_MAX);
        $media = Validator::in($r->input('media'), 'media', ['audio', 'video']);
        Features::require('comm.messaging');
        Features::require($media === 'video' ? 'comm.video_calls' : 'comm.audio_calls');
        if ($calleeId === $uid) {
            Validator::fail('user_id', 'You cannot call yourself.');
        }
        $this->expireStale();

        $match = Eligibility::canCommunicate($uid, $calleeId);
        if ($match === null) {
            throw new ApiException(403, 'CALL_NOT_PERMITTED', 'Calls are only available between matched members with approved profiles.');
        }
        $calleeAllows = Database::value('SELECT allow_calls FROM user_settings WHERE user_id = ?', [$calleeId]);
        if ($calleeAllows !== null && !(bool) $calleeAllows) {
            throw new ApiException(403, 'CALLS_DISABLED', 'This member is not accepting calls.');
        }
        $busy = Database::value(
            "SELECT 1 FROM calls WHERE status IN ('ringing','accepted') AND (caller_id IN (?, ?) OR callee_id IN (?, ?)) LIMIT 1",
            [$uid, $calleeId, $uid, $calleeId]
        );
        if ($busy) {
            throw new ApiException(409, 'BUSY', 'One of you is already on a call.');
        }
        RateLimiter::hit('call:create:' . $uid, Settings::getInt('calls.max_per_hour', 20), 3600);

        $convId = Database::value('SELECT id FROM conversations WHERE match_id = ?', [$match['id']]);
        $callId = Database::insert(
            "INSERT INTO calls (caller_id, callee_id, conversation_id, media, status) VALUES (?, ?, ?, ?, 'ringing')",
            [$uid, $calleeId, $convId, $media]
        );
        $callerName = Eligibility::memberRow($uid)['display_name'] ?? 'Someone';
        Notifier::notify($calleeId, 'call', $media === 'video' ? 'Incoming video call' : 'Incoming audio call', 'From ' . $callerName, ['call_id' => $callId]);
        Audit::log($uid, 'call.created', 'call', $callId, null, null, ['media' => $media]);
        Response::status(201);
        return $this->callPayload($callId, $uid) + ['ring_seconds' => Settings::getInt('calls.ring_seconds', 45)];
    }

    public function show(Request $r): array
    {
        $uid = $r->userId();
        $this->expireStale();
        $call = $this->participantCall((int) $r->param('id'), $uid);
        return $this->format($call, $uid);
    }

    /** Fresh signalling ticket for an in-progress call (e.g. after a page reload). */
    public function ticket(Request $r): array
    {
        $uid = $r->userId();
        $this->expireStale();
        $call = $this->participantCall((int) $r->param('id'), $uid);
        if (!in_array($call['status'], ['ringing', 'accepted'], true)) {
            throw new ApiException(409, 'CALL_NOT_ACTIVE', 'This call is no longer active.');
        }
        return $this->callPayload((int) $call['id'], $uid);
    }

    public function accept(Request $r): array
    {
        $uid = $r->userId();
        $callId = (int) $r->param('id');
        Database::transaction(function () use ($callId, $uid): void {
            $call = Database::one('SELECT * FROM calls WHERE id = ? FOR UPDATE', [$callId]);
            if ($call === null || (int) $call['callee_id'] !== $uid) {
                throw new ApiException(404, 'NOT_FOUND', 'Call not found.');
            }
            if ($call['status'] !== 'ringing') {
                throw new ApiException(409, 'CALL_NOT_RINGING', 'This call can no longer be answered.');
            }
            if (Eligibility::canCommunicate($uid, (int) $call['caller_id']) === null) {
                Database::exec("UPDATE calls SET status = 'failed', end_reason = 'not_permitted', ended_at = NOW() WHERE id = ?", [$callId]);
                throw new ApiException(403, 'CALL_NOT_PERMITTED', 'This call is no longer permitted.');
            }
            Database::exec("UPDATE calls SET status = 'accepted', answered_at = NOW() WHERE id = ?", [$callId]);
        });
        return $this->callPayload($callId, $uid);
    }

    public function decline(Request $r): array
    {
        $uid = $r->userId();
        $callId = (int) $r->param('id');
        Database::transaction(function () use ($callId, $uid): void {
            $call = Database::one('SELECT * FROM calls WHERE id = ? FOR UPDATE', [$callId]);
            if ($call === null || (int) $call['callee_id'] !== $uid) {
                throw new ApiException(404, 'NOT_FOUND', 'Call not found.');
            }
            if ($call['status'] === 'ringing') {
                Database::exec("UPDATE calls SET status = 'declined', end_reason = 'declined', ended_at = NOW() WHERE id = ?", [$callId]);
            }
        });
        return ['ok' => true];
    }

    /** Either participant ends the call: cancels a ring, declines it, or ends an answered call. */
    public function end(Request $r): array
    {
        $uid = $r->userId();
        $callId = (int) $r->param('id');
        Database::transaction(function () use ($callId, $uid): void {
            $call = Database::one('SELECT * FROM calls WHERE id = ? FOR UPDATE', [$callId]);
            if ($call === null || !in_array($uid, [(int) $call['caller_id'], (int) $call['callee_id']], true)) {
                throw new ApiException(404, 'NOT_FOUND', 'Call not found.');
            }
            if ($call['status'] === 'ringing') {
                $status = (int) $call['caller_id'] === $uid ? 'cancelled' : 'declined';
                Database::exec("UPDATE calls SET status = ?, end_reason = ?, ended_at = NOW() WHERE id = ?", [$status, $status, $callId]);
            } elseif ($call['status'] === 'accepted') {
                Database::exec(
                    "UPDATE calls SET status = 'ended', end_reason = 'hangup', ended_at = NOW(),
                     duration_seconds = TIMESTAMPDIFF(SECOND, answered_at, NOW()) WHERE id = ?",
                    [$callId]
                );
            }
        });
        return ['ok' => true];
    }

    /** Call history for the signed-in member, newest first. */
    public function history(Request $r): array
    {
        $uid = $r->userId();
        $this->expireStale();
        $limit = min(100, max(1, (int) $r->query('limit', 30)));
        $rows = Database::all(
            "SELECT c.*, IF(c.caller_id = ?, c.callee_id, c.caller_id) AS other_id
             FROM calls c WHERE c.caller_id = ? OR c.callee_id = ?
             ORDER BY c.id DESC LIMIT {$limit}",
            [$uid, $uid, $uid]
        );
        return ['items' => array_map(fn($c) => $this->format($c, $uid), $rows)];
    }

    /** Marks ringing calls as missed and closes calls left open for more than four hours. */
    private function expireStale(): void
    {
        $ring = Settings::getInt('calls.ring_seconds', 45);
        Database::exec(
            "UPDATE calls SET status = 'missed', end_reason = 'no_answer', ended_at = NOW()
             WHERE status = 'ringing' AND created_at < NOW() - INTERVAL ? SECOND",
            [$ring]
        );
        Database::exec(
            "UPDATE calls SET status = 'ended', end_reason = 'timeout', ended_at = NOW(),
             duration_seconds = TIMESTAMPDIFF(SECOND, answered_at, NOW())
             WHERE status = 'accepted' AND answered_at < NOW() - INTERVAL 4 HOUR"
        );
    }

    private function participantCall(int $callId, int $uid): array
    {
        $call = Database::one('SELECT * FROM calls WHERE id = ?', [$callId]);
        if ($call === null || !in_array($uid, [(int) $call['caller_id'], (int) $call['callee_id']], true)) {
            throw new ApiException(404, 'NOT_FOUND', 'Call not found.');
        }
        return $call;
    }

    private function callPayload(int $callId, int $uid): array
    {
        $call = Database::one('SELECT * FROM calls WHERE id = ?', [$callId]);
        $payload = $this->format($call, $uid);
        $payload['ticket'] = Signalling::ticket($callId, $uid);
        $payload['signalling_url'] = Env::get('SIGNALLING_URL');
        $payload['ice_servers'] = Signalling::iceServers($uid);
        return $payload;
    }

    private function format(array $call, int $uid): array
    {
        $otherId = (int) ($call['other_id'] ?? ((int) $call['caller_id'] === $uid ? $call['callee_id'] : $call['caller_id']));
        $other = Eligibility::memberRow($otherId);
        return [
            'id' => (int) $call['id'],
            'direction' => (int) $call['caller_id'] === $uid ? 'outgoing' : 'incoming',
            'media' => $call['media'],
            'status' => $call['status'],
            'end_reason' => $call['end_reason'],
            'other' => ['user_id' => $otherId, 'display_name' => $other['display_name'] ?? 'Member'],
            'created_at' => $call['created_at'],
            'answered_at' => $call['answered_at'],
            'ended_at' => $call['ended_at'],
            'duration_seconds' => (int) $call['duration_seconds'],
        ];
    }
}
