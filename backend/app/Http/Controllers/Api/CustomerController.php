<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Customer::query();
        if (Customer::salesLinked()) {
            $query->withCount('sales');
        }

        $customers = $query
            ->when($request->search, function ($q) use ($request) {
                $term = $request->search;
                $q->where(fn($sub) => $sub->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"));
            })
            ->orderBy('name')
            ->get()
            ->map(fn($c) => $this->formatCustomer($c));

        return $this->success($customers);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'  => 'required|string|max:150',
            'phone' => 'required|string|max:30|unique:customers,phone',
            'note'  => 'nullable|string',
        ]);

        $customer = Customer::create($request->only(['name', 'phone', 'note']));

        activity_log($request->user()->id, 'creation_client', 'Customer', $customer->id, [
            'name' => $customer->name,
        ]);

        if (Customer::salesLinked()) {
            $customer->loadCount('sales');
        }

        return $this->success($this->formatCustomer($customer), 'Client créé.', 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $ventes = Customer::salesLinked()
            ? $customer->sales()->latest()->get()->map(fn($s) => [
                'id'             => $s->id,
                'receipt_number' => $s->receipt_number,
                'date'           => $s->created_at->format('d/m/Y H:i'),
                'total'          => $s->total,
            ])
            : collect();

        return $this->success([
            'id'            => $customer->id,
            'name'          => $customer->name,
            'phone'         => $customer->phone,
            'note'          => $customer->note,
            'nb_ventes'     => $ventes->count(),
            'total_depense' => Customer::salesLinked() ? (int) $customer->sales()->sum('total') : 0,
            'ventes'        => $ventes,
        ]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $request->validate([
            'name'  => 'sometimes|string|max:150',
            'phone' => ['sometimes', 'string', 'max:30', Rule::unique('customers', 'phone')->ignore($customer->id)],
            'note'  => 'nullable|string',
        ]);

        $customer->update($request->only(['name', 'phone', 'note']));

        activity_log($request->user()->id, 'modification_client', 'Customer', $customer->id);

        if (Customer::salesLinked()) {
            $customer->loadCount('sales');
        }

        return $this->success($this->formatCustomer($customer), 'Client mis à jour.');
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        if ($customer->hasSales()) {
            return $this->error('Impossible : ce client a des ventes enregistrées.', 422);
        }

        activity_log($request->user()->id, 'suppression_client', 'Customer', $customer->id, [
            'name' => $customer->name,
        ]);

        $customer->delete();

        return $this->success(null, 'Client supprimé.');
    }

    private function formatCustomer(Customer $customer): array
    {
        $nbVentes = $customer->sales_count
            ?? (Customer::salesLinked() ? $customer->sales()->count() : 0);

        return [
            'id'        => $customer->id,
            'name'      => $customer->name,
            'phone'     => $customer->phone,
            'note'      => $customer->note,
            'nb_ventes' => $nbVentes,
        ];
    }

    private function success(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    private function error(string $message, int $status = 400): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null], $status);
    }
}
