<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveDeliveryZoneRequest;
use App\Models\DeliveryZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Delivery zones and their shipping fees. Admin routes carry `can:delivery.view|manage`. */
class DeliveryZoneController extends Controller
{
    /**
     * List delivery zones
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => DeliveryZone::query()->orderBy('sort')->orderBy('id')->get()->map(fn (DeliveryZone $z) => $this->present($z))]);
    }

    /**
     * Public delivery zones
     *
     * Active zones for the checkout form.
     */
    public function publicIndex(): JsonResponse
    {
        return response()->json(['data' => DeliveryZone::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get()->map(fn (DeliveryZone $z) => $this->present($z))]);
    }

    /**
     * Create a delivery zone
     */
    public function store(SaveDeliveryZoneRequest $request): JsonResponse
    {
        $zone = DeliveryZone::query()->create($request->validated())->refresh();

        return response()->json(['data' => $this->present($zone)], 201);
    }

    /**
     * Update a delivery zone
     *
     * A new fee applies to later orders only; placed orders keep their shipping total.
     */
    public function update(SaveDeliveryZoneRequest $request, DeliveryZone $zone): JsonResponse
    {
        $zone->update($request->validated());

        return response()->json(['data' => $this->present($zone)]);
    }

    /**
     * Delete a delivery zone
     */
    public function destroy(DeliveryZone $zone): Response
    {
        $zone->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(DeliveryZone $zone): array
    {
        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'shipping_fee' => ['amount' => $zone->shipping_fee, 'currency' => config('payments.currency')],
            'is_active' => $zone->is_active,
            'sort' => $zone->sort,
        ];
    }
}
