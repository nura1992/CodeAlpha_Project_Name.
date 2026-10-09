<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Services\MediaService;

final class MediaController extends Controller
{
    public function chat(array $params): never
    {
        $user = Auth::user();
        if (!$user) Response::error('Unauthenticated',401);
        $messageId = (int)$params['message_id'];
        $msg = Database::one('SELECT * FROM messages WHERE id = :id', ['id'=>$messageId]);
        if (!$msg || empty($msg['media_path'])) Response::notFound('Media not found');
        // Must be conversation member
        $member = Database::one('SELECT 1 FROM conversation_members WHERE conversation_id = :cid AND user_id = :uid AND left_at IS NULL', ['cid'=>$msg['conversation_id'],'uid'=>$user['id']]);
        if (!$member) Response::forbidden('Not a member of this conversation');
        $fs = MediaService::resolvePath((string)$msg['media_path']);
        if (!$fs) Response::notFound('File not found');
        Response::file($fs, (string)($msg['media_mime'] ?: MediaService::mimeFor($fs)), basename($fs), true);
    }
}
