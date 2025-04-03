<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ProductController extends Controller
{

    /**
     * @var string
     */
    const DEFAULT_IMAGE = 'product-placeholder.jpg';

    /**
     * @var float
     */
    const DEFAULT_EXCHANGE_RATE = 0.85;

    /**
     * @return \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory
     */
    public function index()
    {
        $products = Product::latest('id')->paginate(10, ['id', 'name', 'price', 'image', 'description']);
        $exchangeRate = $this->getExchangeRate();
        $products->getCollection()->transform(function ($product) use ($exchangeRate) {
            $product->image = $this->getProductImagePath($product);
            $product->description = Str::limit($product->description, 100);
            $product->price_usd = number_format($product->price, 2);
            $product->price_eur = number_format($product->price * $exchangeRate, 2);
            $product->url = route('products.show', $product->id);
            return $product;
        });
        return view('products.list', compact('products', 'exchangeRate'));
    }

    /**
     * @param Product $product
     * @return \Illuminate\Contracts\View\View|\Illuminate\Contracts\View\Factory|\Illuminate\Http\RedirectResponse
     */
    public function show(Product $product)
    {
        $exchangeRate = $this->getExchangeRate();
        $product->image = asset('img/' . $product->image);
        $product->price_usd = number_format($product->price, 2);
        $product->price_eur = number_format($product->price * $exchangeRate, 2);
        return view('products.show', compact('product', 'exchangeRate'));
    }

    public function getProductImagePath(Product $product): string
    {
        return $product->image && ($product->image !== self::DEFAULT_IMAGE)
            ? asset('storage/img/' . $product->image)
            : asset('img/' . self::DEFAULT_IMAGE);
    }

    /**
     * @return float
     */
    private function getExchangeRate()
    {
        try {
            $rate = Cache::get('exchange_rate');
            if ($rate) return $rate;
            $response = Http::get('https://api.exchangerate-api.com/v4/latest/USD');
            $rate = $response->successful()
                ? ($response->json()['rates']['EUR'] ?? $this::DEFAULT_EXCHANGE_RATE)
                : $this::DEFAULT_EXCHANGE_RATE;
            Cache::put('exchange_rate', $rate, 3600);
            return $rate;
        } catch (\Exception $e) {
            info('Exchange rate API error: ' . $e->getMessage());
            return $this::DEFAULT_EXCHANGE_RATE;
        }
    }
}
