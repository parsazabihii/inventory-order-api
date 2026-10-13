<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CatalogController extends Controller
{
    private function model(string $resource): string
    {
        return match ($resource) {
            'categories' => Category::class,'products' => Product::class,'warehouses' => Warehouse::class
        };
    }

    private function rules(string $resource, ?int $id = null): array
    {
        return match ($resource) {
            'categories' => ['name' => ['required', 'string', 'max:100', Rule::unique('categories')->ignore($id)]],
            'warehouses' => ['name' => 'required|string|max:100', 'code' => ['required', 'string', 'max:40', Rule::unique('warehouses')->ignore($id)]],
            'products' => ['name' => 'required|string|max:150', 'sku' => ['required', 'string', 'max:64', Rule::unique('products')->ignore($id)], 'category_id' => 'required|integer|exists:categories,id', 'price_cents' => 'required|integer|min:0|max:1000000000', 'low_stock_threshold' => 'sometimes|integer|min:0|max:1000000'],
        };
    }

    public function index(Request $r, string $resource)
    {
        $d = $r->validate(['q' => 'sometimes|string|max:100', 'category_id' => 'sometimes|integer|exists:categories,id', 'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $q = $this->model($resource)::query();
        if (isset($d['q'])) {
            $q->where('name', 'ilike', '%'.$d['q'].'%');
        }
        if ($resource === 'products') {
            $q->with('category');
            if (isset($d['category_id'])) {
                $q->where('category_id', $d['category_id']);
            }
        }

        return $q->orderBy('id')->paginate($d['per_page'] ?? 20);
    }

    public function show(int $id, string $resource)
    {
        return response()->json(['data' => $this->model($resource)::findOrFail($id)]);
    }

    public function store(Request $r, string $resource)
    {
        return response()->json(['data' => $this->model($resource)::create($r->validate($this->rules($resource)))], 201);
    }

    public function update(Request $r, int $id, string $resource)
    {
        $model = $this->model($resource)::findOrFail($id);
        $rules = $this->rules($resource, $id);
        foreach ($rules as &$rule) {
            $rule = is_array($rule) ? array_merge(['sometimes'], $rule) : 'sometimes|'.$rule;
        } unset($rule);
        $model->update($r->validate($rules));

        return response()->json(['data' => $model]);
    }

    public function destroy(int $id, string $resource)
    {
        // Referential integrity keeps all recorded order and stock history intact.
        try {
            DB::transaction(fn () => $this->model($resource)::findOrFail($id)->delete());
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23503', '23001'], true)) {
                abort(409, 'This record is referenced by inventory or history and cannot be deleted.');
            } throw $e;
        }

        return response()->noContent();
    }
}
