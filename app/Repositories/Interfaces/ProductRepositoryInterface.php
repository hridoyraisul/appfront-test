<?php

namespace App\Repositories\Interfaces;

use App\Models\Product;

interface ProductRepositoryInterface
{
    public function allPaginated($perPage);
    public function find($id);
    public function create(array $data);
    public function update(Product $product, array $data);
    public function delete(Product $product);
    public function getProductImagePath(Product $product);
}
