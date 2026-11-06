<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Support\NetworkStatistics;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, NetworkStatistics $statistics): View
    {
        $id = $request->query('location');
        $location = is_string($id) && ctype_digit($id) ? Location::findOrFail($id) : null;

        return view('dashboard', $statistics->forLocation($location) + [
            'location' => $location,
            'locations' => Location::with('parent.parent')->orderBy('name')->get(),
        ]);
    }
}
