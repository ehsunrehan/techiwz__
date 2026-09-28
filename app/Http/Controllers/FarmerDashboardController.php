<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Category;
use App\Models\Market;
use App\Models\Review;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FarmerDashboardController extends Controller
{
    private function farmer(Request $request)
    {
        return $request->user()->farmer()->withCount('products')->firstOrFail();
    }

    public function pending(): View
    {
        return view('farmer.pending');
    }

    public function dashboard(Request $request): View
    {
        $farmer = $this->farmer($request);

        $orders = $farmer->orders();
        $bestSellers = $farmer->products()
            ->withSum(['orderItems as sold_units' => fn ($q) => $q->whereHas('order', fn ($o) => $o->where('status', '!=', 'cancelled'))], 'quantity')
            ->orderByDesc('sold_units')
            ->take(5)
            ->get();

        return view('farmer.dashboard', [
            'farmer' => $farmer,
            'stats' => [
                'total_orders' => $orders->count(),
                'pending_orders' => $farmer->orders()->where('status', 'placed')->count(),
                'active_orders' => $farmer->orders()->active()->count(),
                'revenue' => $farmer->orders()->whereIn('status', ['completed', 'accepted', 'ready_for_pickup'])->sum('total_amount'),
            ],
            'recentOrders' => $farmer->orders()->with('customer')->orderByDesc('placed_at')->take(5)->get(),
            'bestSellers' => $bestSellers,
            'lowStock' => $farmer->products()->where('is_active', true)->where('stock_quantity', '<=', 5)->orderBy('stock_quantity')->take(5)->get(),
            'announcements' => Announcement::published()->forAudience('farmer')->latest('published_at')->take(3)->get(),
        ]);
    }

    public function profileEdit(Request $request): View
    {
        $farmer = $this->farmer($request)->load('markets');

        return view('farmer.profile', [
            'farmer' => $farmer,
            'markets' => Market::active()->orderBy('name')->get(),
            'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            'categories' => Category::active()->orderBy('sort_order')->get(),
        ]);
    }

    public function profileUpdate(Request $request): RedirectResponse
    {
        $farmer = $this->farmer($request);

        $validated = $request->validate([
            'stall_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^[0-9]{10,15}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'operating_days' => ['array'],
            'operating_days.*' => ['in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'markets' => ['array'],
            'markets.*' => ['exists:markets,id'],
            'order_cutoff_hours' => ['required', 'integer', 'min:0', 'max:168'],
            'cover_image' => ['nullable', 'image', 'max:4096'],
        ], [
            'phone.regex' => 'The phone number must be 10-15 digits, numbers only (no spaces or symbols).',
        ]);

        if ($request->hasFile('cover_image')) {
            $oldCover = $farmer->cover_image;
            $validated['cover_image'] = $request->file('cover_image')->store('farmers', 'public');
            if ($oldCover) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($oldCover);
            }
        } else {
            unset($validated['cover_image']);
        }

        $markets = $validated['markets'] ?? [];
        unset($validated['markets']);

        $farmer->update($validated);
        $farmer->markets()->sync($markets);

        return back()->with('success', 'Stall profile updated.');
    }

    public function insights(Request $request): View
    {
        $farmer = $this->farmer($request);

        $orders = $farmer->orders()->where('status', '!=', 'declined');

        // Sales for the last 30 days (completed + active).
        $daily = $farmer->orders()
            ->where('placed_at', '>=', now()->subDays(30))
            ->whereIn('status', ['completed', 'accepted', 'ready_for_pickup'])
            ->selectRaw('DATE(placed_at) as day, SUM(total_amount) as revenue, COUNT(*) as orders')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $salesSeries = collect(range(29, 0))->map(function ($i) use ($daily) {
            $day = now()->subDays($i)->toDateString();

            return [
                'day' => now()->subDays($i)->format('j M'),
                'revenue' => (float) ($daily[$day]->revenue ?? 0),
                'orders' => (int) ($daily[$day]->orders ?? 0),
            ];
        });

        $byStatus = $farmer->orders()->selectRaw('status, COUNT(*) as count')->groupBy('status')->pluck('count', 'status');

        $topProducts = $farmer->products()
            ->withSum(['orderItems as sold_units' => fn ($q) => $q->whereHas('order', fn ($o) => $o->where('status', '!=', 'cancelled'))], 'quantity')
            ->withSum(['orderItems as revenue' => fn ($q) => $q->whereHas('order', fn ($o) => $o->where('status', '!=', 'cancelled'))], 'line_total')
            ->orderByDesc('sold_units')
            ->take(8)
            ->get();

        $revenueByMarket = $farmer->orders()
            ->whereIn('status', ['completed', 'accepted', 'ready_for_pickup'])
            ->with('market')
            ->get()
            ->groupBy(fn ($o) => $o->market?->name ?? 'Other')
            ->map->sum('total_amount');

        return view('farmer.insights', [
            'farmer' => $farmer,
            'stats' => [
                'total_orders' => $orders->count(),
                'completed_orders' => $farmer->orders()->where('status', 'completed')->count(),
                'total_revenue' => $orders->whereIn('status', ['completed', 'accepted', 'ready_for_pickup'])->sum('total_amount'),
                'avg_order' => round($orders->whereIn('status', ['completed', 'accepted', 'ready_for_pickup'])->avg('total_amount') ?? 0, 2),
            ],
            'salesSeries' => $salesSeries,
            'byStatus' => $byStatus,
            'topProducts' => $topProducts,
            'revenueByMarket' => $revenueByMarket,
        ]);
    }

    public function reviews(Request $request): View
    {
        $farmer = $this->farmer($request);

        return view('farmer.reviews', [
            'farmer' => $farmer,
            'reviews' => $farmer->reviews()->with(['user', 'product'])->latest()->paginate(10),
            'average' => $farmer->averageRating(),
            'total' => $farmer->totalReviews(),
        ]);
    }

    public function reviewRespond(Request $request, Review $review): RedirectResponse
    {
        abort_unless($review->farmer_id === $request->user()->farmer->id, 403);

        $validated = $request->validate([
            'response' => ['required', 'string', 'max:1000'],
        ]);

        $review->update([
            'farmer_response' => $validated['response'],
            'responded_at' => now(),
        ]);

        return back()->with('success', 'Your response was published.');
    }
}
