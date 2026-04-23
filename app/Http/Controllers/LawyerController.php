<?php

namespace App\Http\Controllers;

use App\Models\Lawyer;
use App\Models\Region;
use App\Models\Review;
use Illuminate\Http\Request;

class LawyerController extends Controller
{
    public function index()
    {
        $regionSlug = request('region');
        $search = request('q');

        $regions = Region::withCount('lawyers')->orderBy('name')->get();

        $lawyersQuery = Lawyer::with('region')
            ->when($regionSlug, function ($q) use ($regionSlug) {
                $q->whereHas('region', fn($r) => $r->where('slug', $regionSlug));
            })
            ->when($search, function ($q) use ($search) {
                $q->where('full_name', 'ilike', "%{$search}%")
                    ->orWhere('certificate_number', 'ilike', "%{$search}%");
            })
            ->orderBy('full_name');

        $lawyers = $lawyersQuery->paginate(12)->withQueryString();

        return view('catalog', compact('regions', 'lawyers', 'regionSlug', 'search'));
    }

    public function show(Lawyer $lawyer)
    {
        $lawyer->load(['region', 'reviews' => function ($q) {
            $q->where('status', 'published')->latest();
        }]);

        return view('lawyers.show', compact('lawyer'));
    }

    public function storeReview(Request $request, Lawyer $lawyer)
    {
        $data = $request->validate([
            'author_name' => ['required', 'string', 'max:255'],
            'author_email' => ['nullable', 'email', 'max:255'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['required', 'string', 'min:10'],
        ]);

        $data['status'] = 'published';
        $data['lawyer_id'] = $lawyer->id;

        Review::create($data);

        return back()->with('status', 'Відгук додано.');
    }
}
