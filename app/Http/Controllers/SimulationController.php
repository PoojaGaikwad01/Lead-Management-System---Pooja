<?php

namespace App\Http\Controllers;

use App\Services\Simulation\LeadSimulator;
use Illuminate\Http\RedirectResponse;

/**
 * Admin "Simulate a lead" menu in the inbox (APP_MODE=mock only; 404 in live mode).
 * One click = one realistic webhook delivery for that source, processed by the real pipeline.
 */
class SimulationController extends Controller
{
    public function store(string $type, LeadSimulator $simulator): RedirectResponse
    {
        abort_unless(LeadSimulator::available(), 404);

        $lead = $simulator->run($type);
        $label = LeadSimulator::label($type);

        return $lead
            ? redirect()->route('inbox.show', $lead)->with('status', "Simulated {$label}: {$lead->display_name}.")
            : redirect()->route('inbox')->with('status', "Simulated {$label}, but it produced no lead.");
    }

    public function clear(): RedirectResponse
    {
        abort_unless(LeadSimulator::available(), 404);

        $count = LeadSimulator::clear();

        return redirect()->route('inbox')->with('status', "Removed {$count} simulated ".str('lead')->plural($count).'.');
    }
}
