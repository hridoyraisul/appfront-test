<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Jobs\SendPriceChangeNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use App\Repositories\Interfaces\ProductRepositoryInterface;

class AdminController extends Controller
{
    const DEFAULT_IMAGE = 'product-placeholder.jpg';

    private $productRepository;

    public function __construct(ProductRepositoryInterface $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    public function loginPage()
    {
        return view('login');
    }

    public function login(Request $request)
    {
        if (Auth::attempt($request->except('_token'))) {
            return redirect()->route('admin.products');
        }
        return redirect()->back()->with('error', 'Invalid login credentials');
    }

    public function logout()
    {
        Auth::logout();
        return redirect()->route('login');
    }

    public function products()
    {
        $products = $this->productRepository->allPaginated(10);
        return view('admin.products', compact('products'));
    }

    public function editProduct(Product $product)
    {
        $product->image = $this->productRepository->getProductImagePath($product);
        return view('admin.edit_product', compact('product'));
    }

    public function updateProduct(Request $request, Product $product)
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
            return redirect()->route('admin.products')->with('success', 'Product updated successfully');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to edit product: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to edit product');
        }
    }

    public function deleteProduct(Product $product)
    {
        $this->productRepository->delete($product);
        return redirect()->route('admin.products')->with('success', 'Product deleted successfully');
    }

    public function addProductForm()
    {
        return view('admin.add_product');
    }

    public function addProduct(Request $request)
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
            return redirect()->route('admin.products')->with('success', 'Product added successfully');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to add product: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to add product');
        }
    }

    private function uploadImage(UploadedFile $file, $oldFile = null)
    {
        try {
            $imageName = $file->hashName();
            $file->move(public_path('img'), $imageName);

            if ($oldFile && ($oldFile != self::DEFAULT_IMAGE)) {
                $oldImagePath = public_path('img/' . $oldFile);
                if (file_exists($oldImagePath)) {
                    unlink($oldImagePath);
                }
            }

            return $imageName;
        } catch (Exception $e) {
            return null;
        }
    }

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
