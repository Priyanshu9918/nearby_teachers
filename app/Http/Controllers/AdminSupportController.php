<?php

namespace App\Http\Controllers;

use App\Reporting\ReportAccessLog;
use App\Services\SupportContactDetails;
use App\Services\SupportTicketManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AdminSupportController extends Controller
{
    public function __construct(private readonly ReportAccessLog $access) {}

    public function contactDetails(Request $request, SupportContactDetails $contact): View
    {
        $this->access->record($request, 'support-contact-settings', 'view');

        return view('admin.support.contact-details', ['contact' => $contact->current()]);
    }

    public function updateContactDetails(Request $request, SupportContactDetails $contact): RedirectResponse
    {
        $data = $request->validate(SupportContactDetails::rules());
        $contact->update($data);
        $this->access->record($request, 'support-contact-settings', 'update', '1');

        return back()->with('status', 'Contact details updated. The app now uses these links.');
    }

    public function updateTicketStatus(Request $request, string $id, SupportTicketManager $tickets): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:pending,in_progress,resolved,rejected']]);
        $ticket = $tickets->updateStatus($id, $data['status'], null);
        abort_if($ticket === null, 404);
        $this->access->record($request, 'support', 'status-update', $id);

        return back()->with('status', 'Ticket status updated to '.str_replace('_', ' ', $ticket->status).'.');
    }
}
