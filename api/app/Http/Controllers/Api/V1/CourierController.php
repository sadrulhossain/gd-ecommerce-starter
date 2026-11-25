<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SaveCourierRequest;
use App\Models\Courier;
use App\Models\Delivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Couriers. Admin routes carry `can:delivery.view|manage`. */
class CourierController extends Controller
{
    /**
     * List couriers
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => Courier::query()->orderBy('name')->orderBy('id')->get()->map(fn (Courier $c) => $this->present($c))]);
    }

    /**
     * Create a courier
     */
    public function store(SaveCourierRequest $request): JsonResponse
    {
        $courier = Courier::query()->create($request->validated())->refresh();

        return response()->json(['data' => $this->present($courier)], 201);
    }

    /**
     * Update a courier
     */
    public function update(SaveCourierRequest $request, Courier $courier): JsonResponse
    {
        $courier->update($request->validated());

        return response()->json(['data' => $this->present($courier)]);
    }

    /**
     * Delete a courier
     *
     * Refused (422) once it has deliveries; deactivate it instead.
     */
    public function destroy(Courier $courier): Response
    {
        if (Delivery::query()->where('courier_id', $courier->id)->exists()) {
            throw ValidationException::withMessages(['courier' => 'This courier has deliveries. Deactivate it instead.']);
        }
        $courier->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(Courier $courier): array
    {
        return ['id' => $courier->id, 'name' => $courier->name, 'phone' => $courier->phone, 'is_active' => $courier->is_active];
    }
}
