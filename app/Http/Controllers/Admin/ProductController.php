<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Jobs\SendPriceChangeNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Repositories\Interfaces\ProductRepositoryInterface;

class ProductController extends Controller
{
    const DEFAULT_IMAGE = 'product-placeholder.jpg';

    private $productRepository;

    public function __construct(ProductRepositoryInterface $productRepository)
    {
        $this->productRepository = $productRepository;
    }


    /**
     * Display the list of products.
     *
     * @return \Illuminate\View\View The view for displaying products.
     */
    public function index()
    {
        $products = $this->productRepository->allPaginated(10);
        return view('admin.products', compact('products'));
    }

    /**
     * Display the edit product form.
     *
     * @param Product $product The product to be edited.
     * @return \Illuminate\View\View The view for editing the product.
     */
    public function edit(Product $product)
    {
        $product->image = $this->productRepository->getProductImagePath($product);
        return view('admin.edit_product', compact('product'));
    }

    /**
     * Update product
     *
     * @param Request $request
     * @param Product $product
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Product $product)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|min:3|unique:products,name,' . $product->id,
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg|max:500',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        DB::beginTransaction();
        try {
            $requestData = $request->only(['name', 'price', 'description']);

            if ($request->hasFile('image')) {
                $requestData['image'] = $this->uploadImage($request->file('image'), $product->image);
            }

            $oldPrice = $product->price;
            $this->productRepository->update($product, $requestData);

            if ($oldPrice != $product->price) {
                $this->handlePriceChangeNotification($product, $oldPrice);
            }

            DB::commit();
            return redirect()->route('products.index')->with('success', 'Product updated successfully');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to edit product: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to edit product');
        }
    }

    public function destroy(Product $product)
    {
        $this->productRepository->delete($product);
        return redirect()->route('products.index')->with('success', 'Product deleted successfully');
    }

    public function create()
    {
        return view('admin.add_product');
    }

    /**
     * Add new product
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|min:3|unique:products,name',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'image' => 'nullable|image|mimes:jpeg,png,jpg|max:500',
        ]);

        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        DB::beginTransaction();
        try {
            $imageName = self::DEFAULT_IMAGE;
            if ($request->hasFile('image')) {
                $imageName = $this->uploadImage($request->file('image'));
            }

            $this->productRepository->create([
                'name' => $request->name,
                'description' => $request->description,
                'price' => $request->price,
                'image' => $imageName,
            ]);

            DB::commit();
            return redirect()->route('products.index')->with('success', 'Product added successfully');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to add product: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to add product');
        }
    }

    /**
     * Upload image and delete old one if exists
     *
     * @param UploadedFile $file
     * @param string|null $oldFile
     * @return string|null
     */
    private function uploadImage(UploadedFile $file, $oldFile = null)
    {
        try {
            $imagePath = $file->store('img', 'public');
            if ($oldFile && $oldFile !== self::DEFAULT_IMAGE) {
                Storage::disk('public')->delete("img/{$oldFile}");
            }
            return basename($imagePath);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Handle price change notification
     *
     * @param Product $product
     * @param float $oldPrice
     */
    private function handlePriceChangeNotification(Product $product, float $oldPrice): void
    {
        $notificationEmail = config('mail.price_notify_email');

        rescue(function () use ($notificationEmail, $product, $oldPrice) {
            if (!filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
                Log::error('Invalid email address for price change notification');
            }

            SendPriceChangeNotification::dispatch(
                $product,
                $oldPrice,
                $product->price,
                $notificationEmail
            );
        }, function ($e) {
            Log::error('Failed to send price change notification: ' . $e->getMessage());
        });
    }
}
