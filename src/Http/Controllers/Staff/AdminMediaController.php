<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Database;
use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\MediaService;

final class AdminMediaController extends Controller
{
    public function verification(array $p): never
    {
        $req=Database::one('SELECT * FROM verification_requests WHERE id=:id',['id'=>$p['id']]); if(!$req) Response::notFound();
        $fs=MediaService::resolvePath((string)$req['media_path']); if(!$fs) Response::notFound('Media expired or not found');
        Response::file($fs, (string)$req['mime'], basename($fs), true);
    }
}
