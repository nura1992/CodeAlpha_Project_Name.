<?php
declare(strict_types=1);
namespace CodeAlpha\Http\Controllers\Staff;

use CodeAlpha\Core\Auth;
use CodeAlpha\Core\Response;
use CodeAlpha\Http\Controllers\Controller;
use CodeAlpha\Services\SupportService;

final class SupportTicketController extends Controller
{
    public function index(): never
    {
        $this->view('staff/support/tickets-index', ['tickets'=>SupportService::staffTickets(['status'=>$this->request->query('status'),'category'=>$this->request->query('category')])]);
    }
    public function show(array $p): never
    {
        $t=SupportService::staffTicket((int)$p['id']); if(!$t) Response::notFound();
        $this->view('staff/support/ticket-show', ['ticket'=>$t,'messages'=>SupportService::ticketMessages((int)$t['id'])]);
    }
    public function reply(array $p): never
    {
        $body=trim((string)$this->request->input('body')); if($body===''){ flash('error','Message required'); Response::redirect('/staff/support/tickets/'.$p['id']); }
        SupportService::addMessage((int)$p['id'], (int)Auth::id(), $body, true);
        Response::redirect('/staff/support/tickets/'.$p['id']);
    }
    public function status(array $p): never
    {
        SupportService::setTicketStatus((int)$p['id'], (string)$this->request->input('status'), (int)Auth::id());
        Response::redirect('/staff/support/tickets/'.$p['id']);
    }
}
