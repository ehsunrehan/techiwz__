<?php

namespace App\Http\Controllers;

use App\Models\Market;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketController extends Controller
{
    public function index(Request $request): View
    {
        $markets = Market::active()
            ->withCount(['farmers' => fn ($q) => $q->whereHas('user', fn ($u) => $u->where('status', 'active'))])
            ->when($request->q, fn ($q, $v) => $q->where(fn ($qq) => $qq->where('name', 'like', "%$v%")->orWhere('city', 'like', "%$v%")->orWhere('address', 'like', "%$v%")))
            ->when($request->day, fn ($q, $v) => $q->whereJsonContains('operating_days', $v))
            ->orderBy('name')
            ->paginate(9)
            ->withQueryString();

        return view('markets.index', [
            'markets' => $markets,
            'mapMarkets' => $markets->getCollection()
                ->filter(fn ($m) => $m->latitude && $m->longitude)
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'lat' => (float) $m->latitude,
                    'lng' => (float) $m->longitude,
                    'days' => $m->operatingDaysLabel(),
                    'url' => route('markets.show', $m),
                ])->values(),
        ]);
    }

    public function show(Market $market): View
    {
        $farmers = $market->farmers()
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->withCount('products')
            ->get()
            ->sortByDesc(fn ($f) => $f->averageRating())
            ->values();

        return view('markets.show', [
            'market' => $market,
            'farmers' => $farmers,
            'isOpenToday' => $market->isOpenToday(),
        ]);
    }
}
