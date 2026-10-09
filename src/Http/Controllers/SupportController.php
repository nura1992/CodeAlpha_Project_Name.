<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers;

use CodeAlpha\Services\SupportService;

final class SupportController extends Controller
{
    public function apiTickets(): never
    {
        $user = $this->user();
        $this->ok(['tickets'=> SupportService::ticketsForUser((int)$user['id'])]);
    }
    public function apiCreateTicket(): never
    {
        $user = $this->user();
        $subject = trim((string)($this->request->input('subject')??'')); if ($subject==='') $this->error('Subject required',422);
        $message = trim((string)($this->request->input('message')?? $this->request->input('body')??'')); if ($message==='') $this->error('Message required',422);
        $t = SupportService::createTicket((int)$user['id'], $subject, (string)($this->request->input('category')??'other'), $message, (string)($this->request->input('priority')??'normal'));
        $this->ok(['ticket'=>$t],201);
    }
    public function apiTicket(array $params): never
    {
        $user = $this->user();
        $t = SupportService::ticket((int)$user['id'], (int)$params['id']);
        if (!$t) $this->error('Ticket not found',404);
        $this->ok(['ticket'=>$t, 'messages'=> SupportService::ticketMessages((int)$t['id'])]);
    }
    public function apiTicketMessage(array $params): never
    {
        $user = $this->user();
        $body = trim((string)($this->request->input('body')?? $this->request->input('message')??'')); if ($body==='') $this->error('Message required',422);
        $res = SupportService::addMessage((int)$params['id'], (int)$user['id'], $body, false);
        $this->ok($res,201);
    }
    public function apiAppeal(): never
    {
        $user = $this->user();
        $subject = trim((string)($this->request->input('subject')??'')); if ($subject==='') $this->error('Subject required',422);
        $message = trim((string)($this->request->input('message')?? $this->request->input('body')??'')); if ($message==='') $this->error('Message required',422);
        $t = SupportService::createAppeal((int)$user['id'], $subject, $message);
        $this->ok(['ticket'=>$t],201);
    }
}
