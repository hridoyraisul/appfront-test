<?php

// app/Repositories/ProductRepository.php
namespace App\Repositories;

use App\Models\Product;
use App\Repositories\Interfaces\ProductRepositoryInterface;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductRepository implements ProductRepositoryInterface
{
    const DEFAULT_IMAGE = 'product-placeholder.jpg';

    public function allPaginated($perPage): LengthAwarePaginator
    {
        return Product::latest('id')
            ->paginate($perPage)
            ->through(function ($product) {
                $product->image = $this->getProductImagePath($product);
                $product->price = number_format($product->price, 2);
                return $product;
            });
    }

    public function find($id): ?Product
    {
        return Product::find($id);
    }

    public function create(array $data): Product
    {
        return Product::create($data);
    }

    public function update(Product $product, array $data): bool
    {
        return $product->update($data);
    }

    public function delete(Product $product): bool
    {
        return $product->delete();
    }

    public function getProductImagePath(Product $product): string
    {
        if ($product->image && ($product->image != self::DEFAULT_IMAGE)) {
            $imagePath = public_path('img/' . $product->image);
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }
        return asset('img/' . $product->image);
    }
}
