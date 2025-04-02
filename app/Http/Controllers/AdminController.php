<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Jobs\SendPriceChangeNotification;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    const DEFAULT_IMAGE = 'product-placeholder.jpg';
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
        $products = Product::latest('id')->paginate(10);
        $products->getCollection()->transform(function ($product) {
            $product->image = asset($product->image);
            $product->price = number_format($product->price, 2);
            return $product;
        });
        return view('admin.products', compact('products'));
    }

    public function editProduct(Product $product)
    {
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
        $requestData = $request->validated()->except('image');
        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $imageName = $file->hashName();
            $file->move(public_path('img'), $imageName);
            $requestData['image'] = $imageName;
            if ($product->image && ($product->image != $this::DEFAULT_IMAGE)) {
                $oldImagePath = public_path($product->image);
                if (file_exists($oldImagePath)) {
                    unlink($oldImagePath);
                }
            }
        }
        $oldPrice = $product->price;
        $product->update($requestData);

        if ($oldPrice != $product->price) {
            $notificationEmail = env('PRICE_NOTIFICATION_EMAIL', 'admin@example.com');
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
        return redirect()->route('admin.products')->with('success', 'Product updated successfully');
    }

    public function deleteProduct(Product $product)
    {
        if ($product->image && ($product->image != $this::DEFAULT_IMAGE)) {
            $imagePath = public_path($product->image);
            if (file_exists($imagePath)) {
                unlink($imagePath);
            }
        }
        $product->delete();
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
            $imageName = $this::DEFAULT_IMAGE;
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $imageName = $file->hashName();
                $file->move(public_path('img'), $imageName);
            }
            Product::create([
                'name' => $request->name,
                'description' => $request->description,
                'price' => $request->price,
                'image' => $imageName,
            ]);
            DB::commit();
            return redirect()->route('admin.products')->with('success', 'Product added successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to add product: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to add product');
        }
    }
}
