<?php

namespace MedCore\Modules\Admin;

use MedCore\Core\Auth;
use MedCore\Core\Request;
use MedCore\Core\Response;
use MedCore\Services\NotificationService;

/**
 * In-app notifications for the current user.
 */
final class NotificationController
{
    public function index(): void
    {
        $userId = (int) Auth::id();
        Response::view('notifications/index', [
            'title'         => 'Notifications',
            'notifications' => NotificationService::forUser($userId),
        ]);
    }

    public function markRead(Request $request, string $id): void
    {
        NotificationService::markRead((int) Auth::id(), (int) $id);
        Response::redirect('/notifications');
    }

    public function markAllRead(): void
    {
        NotificationService::markAllRead((int) Auth::id());
        Response::redirect('/notifications');
    }
}
