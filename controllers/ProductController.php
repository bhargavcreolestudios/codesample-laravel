<?php
/**
 * API calls related to products
 *
 * PHP version 5 and 7
 *
 * LICENSE: This source file is subject to version 3.01 of the PHP license
 * that is available through the world-wide-web at the following URI:
 * http://www.php.net/license/3_01.txt.  If you did not receive a copy of
 * the PHP License and are unable to obtain it through the web, please
 * send a note to license@php.net so we can mail you a copy immediately.
 *
 * @package    ProductController
 * @author     Bhargav Bhanderi (bhargav@creolestudios.com)
 * @copyright  2018 ModTod
 * @license    http://www.php.net/license/3_01.txt  PHP License 3.01
 * @version    SVN Branch: backend_api_controller
 * @since      File available since Release 1.0.0
 * @deprecated N/A
 */

/**
Pre-Load all necessary library & name space
 */
namespace App\Http\Controllers;

use App\Attributes;
use App\Cart;
use App\CartOrder;
use App\CartProduct;
use App\Coupon;
use App\Helpers\CanedaPost;
use App\Helpers\Firebase;
use App\Http\Controllers\Controller;
use App\Mail\ShippingLabel;
use App\Order;
use App\Product;
use App\ProductComments;
use App\SystemNotifications;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tymon\JWTAuth\Facades\JWTAuth;

class ProductController extends Controller
{

    /**
     * Set global per page records for pagination.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public $perPage = 20;

    /*
    |--------------------------------------------------------------------------
    | Createproduct
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to create a new product
    | In Params : void
    | Date      : 20th August 2018
    |
     */

    public function Createproduct(Request $request)
    {
        try {
            \DB::beginTransaction();
            $returnData = UtilityController::ValidationRules($request->all(), 'Product');
            ## return if validation failed
            if (!$returnData['status']) {
                return response()->json($returnData, 400);
            }
            $returnData = UtilityController::Setreturnvariables();
            ## get current logged user object
            $user = $request->user();

            $userShipping = $user->shippingAddress;
            if ($userShipping) {
                $postalCode = $userShipping->postal_code;
            } else {
                $postalCode = 0;
            }
            $productDetail                = $request->all();
            $productDetail['postal_code'] = $postalCode;
            ## Create new product
            $newProduct = $user->products()->create($productDetail);
            if ($newProduct) {
                ## give attributes if product is created
                $attribs = $newProduct->attributes()->sync($request->attribs);
                if ($attribs) {
                    ## upload images and get their names to be stored in database
                    $imageResponse = $this->uploadImages($request->product_image);
                    if (!empty($imageResponse)) {
                        ## store image record into database
                        $addImages = $newProduct->images()->createMany($imageResponse);
                        if (!empty($addImages)) {
                            $shoppingFor = $newProduct->attributes->pluck('id')->toArray();

                            $users = \App\User::active()->normal()->notMe($user->id)->whereHas('shoppingFor', function ($query) use ($shoppingFor) {
                                $query->whereIn('size', $shoppingFor)->orWhereIn('gender', $shoppingFor);
                            })->get();
                            if (!$users->isEmpty()) {
                                $productId = $newProduct->id;
                                ## add new notification and send it to related users
                                $notification = $users->each(function ($user, $key) use ($productId) {
                                    $message                    = 'A new product has been added.';
                                    $notification               = new \App\UserNotifications;
                                    $notification->user_id      = $user->id;
                                    $notification->reference_id = $productId;
                                    $notification->type         = 2; //product
                                    $notification->text         = $message;
                                    if ($notification->save()) {
                                        $tokens = $user->deviceTokens->pluck('device_token')->toArray();
                                        if (!empty($tokens)) {
                                            $firebase = new Firebase($tokens, $message);
                                            $firebase->sendPush($notification->toArray());
                                        }
                                    }
                                });
                                $system              = new SystemNotifications;
                                $system->user_id     = $request->user()->id;
                                $system->description = $request->user()->first_name . ' ' . $request->user()->last_name . " posted a new product";
                                $system->type        = 2;
                                $system->save();
                            }
                            ## if everything is okay, generate response and commit transactions.
                            $returnData = UtilityController::Generateresponse(1, 'PRODUCT_CREATED', Response::HTTP_OK, '', 1);
                            \DB::commit();
                        }
                    }
                }
            }

            return $returnData;
        } catch (\Exception $e) {
            \DB::rollback();
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Updateproduct
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to update product
    | In Params : void
    | Date      : 9th October 2018
    |
     */

    public function Updateproduct(Request $request)
    {
        try {
            \DB::beginTransaction();
            $valid = true;
            $rules = [
                'id'              => 'required|exists:products,id,user_id,' . $this->user()->id,
                'title'           => 'sometimes|required',
                'description'     => 'sometimes|required',
                'price'           => 'sometimes|required',
                'status'          => 'sometimes|required',
                'attribs'         => 'sometimes|required|array',
                'attribs.*'       => 'exists:sub_attributes,id',
                'product_image'   => 'sometimes|required|array',
                'product_image.*' => 'image|mimes:jpg,png,jpeg',
                'delete_images'   => 'sometimes|required|array',
                'delete_images.*' => 'exists:product_images,id,product_id,' . $request->id,
                'postal_code'     => 'sometimes|required|regex:/^[A-Z0-9^]+$/',

            ];
            ## validate request
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                ## implode messages
                $messages   = implode(" & ", $validator->messages()->all());
                $returnData = UtilityController::Generateresponse(0, $messages, Response::HTTP_BAD_REQUEST, '', 1);
            } else {
                $returnData = UtilityController::Setreturnvariables();
                $product    = Product::find($request->id);
                if ($product->update($request->all())) {
                    ## if attributes updated
                    if ($request->has('attribs')) {
                        $attribsUpdate = $product->attributes()->sync($request->attribs);
                        if (!$attribsUpdate) {
                            $valid = false;
                        }
                    }
                    ## delete images if delete call
                    if ($request->has('delete_images')) {
                        $images = \App\ProductImages::whereIn('id', $request->delete_images)->get();
                        $images->each(function ($image, $key) {
                            ## delete image from storage then from database
                            if ($this->deleteImage($image)) {
                                $image->delete();
                            } else {
                                $valid = false;
                            }
                        });
                    }
                    ## add new images if image available
                    if ($request->hasFile('product_image')) {
                        $imageResponse = $this->uploadImages($request->product_image);
                        if (!empty($imageResponse)) {
                            ## store image record into database
                            $createImages = $product->images()->createMany($imageResponse);
                            if (!$createImages) {
                                $valid = false;
                            }
                        }
                    }
                    if ($valid) {
                        \DB::commit();
                        $responseData = Product::with('attributes', 'images')->find($request->id);
                        $returnData   = UtilityController::Generateresponse(1, 'PRODUCT_UPDATED', Response::HTTP_OK, $responseData, 1);
                    }
                }
            }

            return $returnData;
        } catch (\Exception $e) {
            \DB::rollback();
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Productlisting
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get list of products
    | In Params : void
    | Date      : 20th August 2018
    | Sorting   :1 - Newest First, 2 - Price H-L, 3 - Price L-H, 4 - Seller Rating L-H, 5 - Seller Rating H-L
    |
     */

    public function Productlisting(Request $request)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            if ($request->has('page')) {
                $page = $request->page;
            } else {
                $page = 1;
            }
            $userId = $request->user()->id;
            ## products
            $products = Product::select('*')->isActive()->notMe($userId);

            if ($request->has('id') && !empty($request->id)) {
                $categories = [];
                foreach ($request->id as $key => $value) {
                    $subAttrib             = \App\SubAttributes::find($value);
                    $subCat                = $subAttrib->attribute_id;
                    $categories[$subCat][] = $subAttrib->id;
                }
                $collection = $categories;
            } else {
                $collection = [];
            }

            ## filter for product attributes
            if (!empty($collection)) {
                $ids = $collection;
                foreach ($ids as $key => $id) {
                    $products = $products->whereHas('attributes', function ($query) use ($id, $key) {
                        $query->where('sub_attributes.attribute_id', $key)->whereIn('sub_attributes.id', $id);
                    });
                }
            }
            ## search string. if user typed anything
            if ($request->has('search_string')) {
                $search = $request->search_string;
                ## all products
                $products = $products->where(function ($query) use ($search) {
                    $query->where('title', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%');
                });
            }
            ## get related records
            if ($request->has('sort_by')) {
                if ($request->sort_by == 2) {
                    ## price high to low
                    $products = $products->orderBy('price', 'desc');
                } elseif ($request->sort_by == 3) {
                    ## price low to high
                    $products = $products->orderBy('price', 'asc');
                } elseif ($request->sort_by == 4) {
                    ## sold out
                    $products = $products->where('status', 3);
                } else {
                    ## noramal sorting by user products
                    $products = $products->orderBy('id', 'desc');
                }
            } else {
                $products = $products->orderBy('id', 'desc');
            }

            $products   = $products->with('images');
            $products   = $products->with('attributes');
            $products   = $products->get();
            $pagination = UtilityController::Custompaginate($products, $this->perPage, $page, ['path' => url('/productListings')])->toArray();

            $responseData = ['status' => 1, 'status_code' => 200, 'message' => 'Success!'] + $pagination;
            return response()->json($responseData, 200);
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Adminproductlist
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to list admin products
    | In Params : void
    | Date      : 23rd October 2018
    | Sorting   :1 - Newest First, 2 - Price H-L, 3 - Price L-H, 4 - Seller Rating L-H, 5 - Seller Rating H-L
    |
     */

    public function Adminproductlist(Request $request)
    {
        try {

            $returnData = UtilityController::Setreturnvariables();
            if ($request->has('page')) {
                $page = $request->page;
            } else {
                $page = 1;
            }
            ## products

            $products = Product::select('*');
            ## filter for product attributes

            if ($request->has('id') && !empty($request->id)) {
                $categories = [];
                foreach ($request->id as $key => $value) {
                    $subAttrib             = \App\SubAttributes::find($value);
                    $subCat                = $subAttrib->attribute_id;
                    $categories[$subCat][] = $subAttrib->id;
                }
                $collection = $categories;
            } else {
                $collection = [];
            }

            ## filter for product attributes
            if (!empty($collection)) {
                $ids = $collection;
                foreach ($ids as $key => $id) {
                    $products = $products->whereHas('attributes', function ($query) use ($id, $key) {
                        $query->where('sub_attributes.attribute_id', $key)->whereIn('sub_attributes.id', $id);
                    });
                }
            }

            ## search string. if user typed anything
            if ($request->has('search')) {
                $search = $request->search;
                ## all products
                $products = $products->where(function ($query) use ($search) {
                    $query->where('title', 'like', '%' . $search . '%')
                        ->orWhere('description', 'like', '%' . $search . '%');
                });
            }
            ## get related records
            // \DB::enableQueryLog();

            // print_r(\DB::getQueryLog());die;
            if ($request->has('sort_by')) {
                if ($request->sort_by == 2) {
                    ## price high to low
                    $products = $products->orderBy('price', 'desc');
                } elseif ($request->sort_by == 3) {
                    ## price low to high
                    $products = $products->orderBy('price', 'asc');
                } elseif ($request->sort_by == 4) {
                    ## sold out
                    $products = $products->where('status', 3);
                } else {
                    ## noramal sorting by user products
                    $products = $products->orderBy('id', 'desc');
                }
            } else {
                $products = $products->orderBy('id', 'desc');
            }

            $products = $products->with(['images', 'attributes.parent', 'user', 'comments.subComments.user', 'comments.user', 'buyers', 'buyer']);
            $products = $products->with('attributes');
            $products = $products->withCount('favourited');
            $products = $products->get();
            if ($request->has('sort_by') && $request->sort_by == 5) {
                $products = $products->sortByDesc(function ($item) {
                    return $item->user->rating;
                })->values();
            }

            $pagination = UtilityController::Custompaginate($products, $this->perPage, $page, ['path' => url('/admin/productListings')])->toArray();

            $responseData = ['status' => 1, 'status_code' => 200, 'message' => 'Success!'] + $pagination;
            return response()->json($responseData, 200);
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getuserfavourites
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get list of favourite products of user
    | In Params : void
    | Date      : 31st August 2018
    |
     */

    public function Getuserfavourites()
    {
        try {
            $returnData   = UtilityController::Setreturnvariables();
            $user         = $this->user();
            $favourites   = $user->favourite()->paginate($this->perPage)->toArray();
            $responseData = ['status' => 1, 'status_code' => 200, 'message' => 'Success!'] + $favourites;
            return response()->json($responseData, 200);
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Togglefavouriteproduct
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to favourite/unfavourite product
    | In Params : void
    | Date      : 31st August 2018
    |
     */

    public function Togglefavouriteproduct(Request $request)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $rules      = [
                'id'     => 'required|exists:products,id',
                'is_fav' => 'required',
            ];
            ## validate request
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                ## implode messages
                $messages   = implode(" & ", $validator->messages()->all());
                $returnData = UtilityController::Generateresponse(0, $messages, Response::HTTP_BAD_REQUEST, '', 1);
            } else {
                $user = $this->user();

                if ($request->is_fav == 1 || $request->is_fav == 'true') {
                    $message = 'PRODUCT_FAVOURITE';
                    $user->favourites()->attach($request->id);
                } else {
                    $message = 'PRODUCT_UNFAVOURITE';
                    $user->favourites()->detach($request->id);
                }

                $returnData = UtilityController::Generateresponse(1, $message, Response::HTTP_OK, '', 1);
            }

            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getproductdetails
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get product details
    | In Params : void
    | Date      : 20th September 2018
    |
     */

    public function Getproductdetails($productId)
    {
        try {
            $rules = [
                'id' => 'required|exists:products,id',
            ];
            ## validate request
            $validator = \Validator::make(['id' => $productId], $rules);
            if ($validator->fails()) {
                ## implode messages
                $messages   = implode(" & ", $validator->messages()->all());
                $returnData = UtilityController::Generateresponse(0, $messages, Response::HTTP_BAD_REQUEST, '', 1);
            } else {
                $product = Product::details()->find($productId);
                $ratings = \App\UserReviewRating::where('user_id', $product->user_id)->avg('user_rating');
                $product->setAttribute('user_rating', $ratings ?: 0);

                $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', Response::HTTP_OK, $product, 1);
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Commentproduct
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to comment on product/comment
    | In Params : void
    | Date      : 20th September 2018
    |
     */

    public function Commentproduct(Request $request)
    {
        try {
            \DB::beginTransaction();
            $rules = [
                'id'          => 'required',
                'comment'     => 'required',
                'sub_comment' => 'required|boolean',
            ];
            ## validate request
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                ## implode messages
                $messages   = implode(" & ", $validator->messages()->all());
                $returnData = UtilityController::Generateresponse(0, $messages, Response::HTTP_BAD_REQUEST, '', 1);
            } else {
                $returnData = UtilityController::Setreturnvariables();
                ## merge user_id into the request.
                $request->merge(['user_id' => $request->user()->id]);
                if ($request->sub_comment) {
                    ## if sub_comment is true then parent will be comment table itself
                    $parent = ProductComments::find($request->id);
                } else {
                    ## if sub comment is false then parent table will be product
                    $parent = Product::find($request->id);
                }
                if ($parent) {
                    ## create new comment from parent table
                    $newComment = $parent->comments()->create($request->all());
                    if ($newComment) {
                        ## commit transaction if everything works good and return success response
                        \DB::commit();
                        $returnData = UtilityController::Generateresponse(1, 'COMMENT_ADDED', Response::HTTP_OK, $newComment, 1);
                    }
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            \DB::rollback();
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Cartactions
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to add/remove cart
    | In Params : void
    | Date      : 20th September 2018
    |
     */

    public function Cartactions(Request $request)
    {
        try {
            \DB::beginTransaction();
            $rules = [
                'id'        => 'required|exists:products,id,status,1',
                'is_remove' => 'required|boolean',
            ];
            ## validate request
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                ## implode messages
                $messages   = implode(" & ", $validator->messages()->all());
                $returnData = UtilityController::Generateresponse(0, $messages, Response::HTTP_BAD_REQUEST, '', 1);
            } else {
                $valid      = false;
                $returnData = UtilityController::Setreturnvariables();
                $product    = Product::select('*');
                if (!$request->is_remove) {
                    $product = $product->where('status', 1);
                }
                $product = $product->find($request->id);
                $carts   = Cart::where('user_id', $request->user()->id)->first();
                if (!$request->is_remove) {
                    if (!$carts) {
                        ## create a new cart
                        $cart          = new Cart();
                        $cart->user_id = $request->user()->id;
                        if ($cart->save()) {
                            $order            = new CartOrder();
                            $order->cart_id   = $cart->id;
                            $order->shipping  = Config('constant.common_variables.DEFAULT_SHIPPING');
                            $order->seller_id = $product->user_id;
                            if ($order->save()) {
                                $addProduct = $order->products()->create(['product_id' => $product->id]);
                                if ($addProduct) {
                                    $recalculate = $order->reCalculate();
                                    if ($recalculate) {
                                        $valid = true;
                                    }
                                }
                            }
                        }
                    } else {
                        ## update into existing cart
                        $order = CartOrder::where('cart_id', $carts->id)->where('seller_id', $product->user_id)->first();
                        if ($order) {
                            $exists = CartProduct::where('order_id', $order->id)->where('product_id', $product->id)->first();
                            if (!$exists) {
                                $addProduct = $order->products()->create(['product_id' => $product->id]);
                                if ($addProduct) {
                                    $recalculate = $order->reCalculate();
                                    if ($recalculate) {
                                        $valid = true;
                                    }
                                }
                            } else {
                                $valid = true;
                            }
                        } else {
                            $order            = new CartOrder();
                            $order->cart_id   = $carts->id;
                            $order->shipping  = Config('constant.common_variables.DEFAULT_SHIPPING');
                            $order->seller_id = $product->user_id;
                            if ($order->save()) {
                                $addProduct = $order->products()->create(['product_id' => $product->id]);
                                if ($addProduct) {
                                    $recalculate = $order->reCalculate();
                                    if ($recalculate) {
                                        $valid = true;
                                    }
                                }
                            }
                        }
                    }
                } else {
                    if ($carts) {
                        $productId = $product->id;
                        $order     = CartOrder::where('cart_id', $carts->id)->whereHas('products', function ($query) use ($productId) {
                            $query->where('product_id', $productId);
                        })->first();
                        if ($order) {
                            if ($order->total_products > 1) {
                                $delete = CartProduct::where('order_id', $order->id)->where('product_id', $productId)->delete();
                                if ($delete) {
                                    $recalculate = $order->reCalculate();
                                    if ($recalculate) {
                                        $valid = true;
                                    }
                                }
                            } else {
                                if ($carts->total_orders <= 1) {
                                    $deleteProducts = $order->products()->delete();
                                    $delete         = $order->delete();
                                    $deleteCart     = $carts->delete();
                                } else {
                                    $deleteProducts = $order->products()->delete();
                                    $delete         = $order->delete();
                                    $deleteCart     = true;
                                }
                                if ($delete && $deleteProducts && $deleteCart) {
                                    $valid = true;
                                }
                            }
                        }
                    }
                }

                if ($valid == true) {
                    $cart = Cart::with(['orders.products.product.images', 'orders.products.product.attributes', 'orders.provider' => function ($query) {$query->select('id', 'first_name', 'last_name', 'user_image');}, 'products.product.images'])->where('user_id', $request->user()->id)->first();
                    \DB::commit();
                    $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', Response::HTTP_OK, $cart, 1);
                }
            }

            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getusercart
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get cart of user
    | In Params : void
    | Date      : 25th September 2018
    |
     */

    public function Getusercart()
    {
        try {
            $carts = Cart::with(['orders.products.product.images', 'orders.products.product.attributes', 'orders.provider' => function ($query) {$query->select('id', 'first_name', 'last_name', 'user_image');}, 'products.product.images'])->where('user_id', $this->user()->id)->first();
            $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', Response::HTTP_OK, $carts, 1);
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getmyproducts
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get logged user products
    | In Params : void
    | Date      : 8th October 2018
    |
     */

    public function Getmyproducts(Request $request)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $products   = Product::with('attributes', 'images')->where('user_id', $request->user()->id);
            if ($request->has('status')) {
                $products = $products->where('status', $request->status);
            }
            $products   = $products->paginate($this->perPage);
            $returnData = ['status' => 1, 'status_code' => 200, 'message' => 'Success!'] + $products->toArray();
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | user
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get current logged user
    | In Params : void
    | Date      : 9th August 2018
    |
     */

    public function user()
    {
        return JWTAuth::toUser();
    }

    /*
    |--------------------------------------------------------------------------
    | uploadImages
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to upload images
    | In Params : void
    | Date      : 20th August 2018
    |
     */

    public function uploadImages($file)
    {
        try {
            if (is_array($file)) {
                ## if there are multiple images it will be calling
                ## the same function in loop
                $storageName = [];
                foreach ($file as $key => $value) {
                    ## store response in variable that will be returned in last
                    $storageName[] = $this->uploadImages($value);
                }
            } else {
                $extention = $file->guessClientExtension();
                $directory = "product_images";
                $append    = rand(0, 999999);
                $fileName  = Carbon::now()->timestamp . $append . '.' . $extention;
                $path      = "$directory/$fileName";
                ## 23th November 2018
                ## you will not get proper image width height sometimes
                ## when you capture the image with Mobile camera
                ## i have integrated Orientation in middle of storing images
                ## for above reason
                $file = \Image::make($file->getRealpath());
                $file->orientate();
                ## save file
                $storagePath = Storage::put($path, (string) $file->encode());
                ## get file path
                $filePath = Storage::path($path);
                ## get image height & width

                list($width, $height) = getimagesize($filePath);

                ## Get response data with records to be stored in database.
                $storageName           = [];
                $storageName['name']   = $fileName;
                $storageName['size']   = Storage::size($path);
                $storageName['width']  = $width;
                $storageName['height'] = $height;

            }
            ## return records
            return $storageName;

        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return [];
        }

    }

    /*
    |--------------------------------------------------------------------------
    | uploadDisputeImages
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to upload dispute images
    | In Params : void
    | Date      : 19th November 2018
    |
     */

    public function uploadDisputeImages($file)
    {
        try {
            if (is_array($file)) {
                $storageName = [];
                foreach ($file as $key => $value) {
                    $storageName[] = $this->uploadDisputeImages($value);
                }
            } else {
                $extention = $file->guessClientExtension();
                $directory = "dispute_images";
                $append    = rand(0, 999999);
                $fileName  = Carbon::now()->timestamp . $append . '.' . $extention;
                ## save file
                $storagePath = Storage::putFileAs($directory, $file, $fileName, 'public');
                ## get storage path
                $path = "$directory/$fileName";
                ## get file path
                $filePath = Storage::path($path);
                ## function to get image height-width
                list($width, $height)  = getimagesize($filePath);
                $storageName           = [];
                $storageName['name']   = basename($storagePath);
                $storageName['size']   = Storage::size($path);
                $storageName['width']  = $width;
                $storageName['height'] = $height;

            }
            ## return file name
            return $storageName;

        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return [];
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Submitorder
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to submit an order
    | In Params : void
    | Date      : 16th November 2018
    |
     */

    public function Submitorder(Request $request)
    {
        try {
            \DB::beginTransaction();
            $returnData = UtilityController::Setreturnvariables();
            if ($request->has('card_id') || $request->has('token_id')) {
                $cart      = Cart::where('user_id', $request->user()->id)->first();
                $stripeKey = Config('services.stripe.key');
                \Stripe\Stripe::setApiKey($stripeKey);
                if ($cart) {
                    $newOrders       = [];
                    $valid           = true;
                    $orders          = $cart->orders;
                    $shippingAddress = $request->user()->shippingAddress;
                    $billingAddress  = \App\Addresses::billingAddress()->where('user_id', $request->user()->id)->first();
                    $shippingAddress->city;
                    $shippingAddress->state;
                    $billingAddress->city;
                    $billingAddress->state;
                    $s_address    = $this->convertAddressObject($shippingAddress, 1);
                    $b_address    = $this->convertAddressObject($billingAddress, 2);
                    $addresses    = array_merge($s_address, $b_address);
                    $customer     = $cart->user_id;
                    $grandTotal   = $cart->total;
                    $buyerCredits = $request->user()->credits;
                    if ($grandTotal < $buyerCredits) {
                        $userCredit   = $buyerCredits - $grandTotal;
                        $buyerCredits = $grandTotal;
                    } else {
                        $userCredit = 0;
                    }
                    foreach ($orders as $key => $order) {
                        $orderObject            = $order->toArray();
                        $productIds             = $order->products->pluck('product_id')->toArray();
                        $products               = Product::whereIn('id', $productIds)->get();
                        $orderObject['user_id'] = $customer;
                        if ($buyerCredits > 0) {
                            $creditsUsed                 = ($buyerCredits * $order['total_cost']) / $grandTotal;
                            $orderObject['credits_used'] = $creditsUsed;
                            $userToPay                   = $order['total_cost'] - $creditsUsed;
                            $orderObject['user_paid']    = $userToPay;
                        } else {
                            $userToPay                   = $orderObject['total_cost'];
                            $orderObject['credits_used'] = 0;
                            $orderObject['user_paid']    = $orderObject['total_cost'];
                        }
                        $orderObject = array_merge($orderObject, $addresses);
                        $newOrder    = Order::create($orderObject);
                        if ($newOrder) {
                            foreach ($products as $key => $product) {
                                $attributes = $product->attributes;
                                $attributes->each(function ($attribute) {
                                    $attribute->parent;
                                });
                                // $attributes->parent;
                                $newProduct                    = [];
                                $newProduct['product_id']      = $product->id;
                                $newProduct['price']           = $product->price;
                                $commision                     = 100 - $product->commision;
                                $commision                     = ($product->price * $commision) / 100;
                                $newProduct['seller_amount']   = $commision;
                                $newProduct['product_name']    = $product->title;
                                $newProduct['product_options'] = $attributes->toArray();
                                $create                        = $newOrder->products()->create($newProduct);
                                if ($create) {
                                    $product->status = 3;
                                    $product->save();
                                } else {
                                    $valid = false;
                                }
                            }
                            $newOrders[] = $newOrder->id;
                        } else {
                            $valid = false;
                        }
                    }
                    if ($valid) {
                        ## payment starts if valid
                        $listOrders = Order::find($newOrders);
                        foreach ($listOrders as $key => $singleOrder) {
                            if ($singleOrder->user_paid > 0) {
                                $amount = $singleOrder->user_paid * 100;
                                if ($request->has('card_id')) {
                                    ## if customer already have card saved
                                    $token  = $request->card_id;
                                    $charge = \Stripe\Charge::create([
                                        'amount'        => $amount,
                                        'currency'      => 'cad',
                                        'customer'      => $request->user()->customer_id,
                                        'source'        => $token,
                                        'receipt_email' => $request->user()->email,
                                    ]);
                                    $transaction_id = $charge->id;
                                    $track_id       = $charge->balance_transaction;
                                } elseif ($request->has('token_id')) {
                                    ## if new card have to be created
                                    $token = $request->token_id;
                                    if ($request->has('is_saved_card') && $request->is_saved_card) {
                                        $customer = \Stripe\Customer::retrieve($request->user()->customer_id);
                                        $source   = $customer->sources->create(array("source" => $token));
                                        $charge   = \Stripe\Charge::create([
                                            'amount'        => $amount,
                                            'currency'      => 'cad',
                                            'customer'      => $request->user()->customer_id,
                                            'source'        => $source->id,
                                            'receipt_email' => $request->user()->email,
                                        ]);
                                    } else {
                                        $charge = \Stripe\Charge::create([
                                            "amount"        => $amount,
                                            "currency"      => "cad",
                                            "source"        => $token,
                                            'receipt_email' => $request->user()->email,
                                        ]);
                                    }
                                    $transaction_id = $charge->id;
                                    $track_id       = $charge->balance_transaction;
                                }
                                $singleOrder->transaction_id = $transaction_id;
                                $singleOrder->tracking_id    = $track_id;
                            } else {
                                $singleOrder->transaction_id = 0;
                                $singleOrder->tracking_id    = 0;
                            }
                            if ($singleOrder->save()) {
                                $this->Sendfavouritesold($singleOrder->id);
                            } else {
                                $valid = false;
                            }
                        }
                        if ($valid) {
                            $user          = \App\User::find($request->user()->id);
                            $user->credits = $userCredit;
                            $user->save();
                            \DB::commit();
                            $creditsUsed     = isset($creditsUsed) ? $creditsUsed : 0;
                            $userToPay       = isset($userToPay) ? $userToPay : 0;
                            $responseMessage = 'Order has been placed. ModTod Credits used: ' . $creditsUsed . ', Stripe Payment: ' . $userToPay;
                            $returnData      = UtilityController::Generateresponse(1, $responseMessage, Response::HTTP_OK, '', 1);
                        }
                    }

                } else {
                    $returnData = UtilityController::Generateresponse(0, 'NO_CART', Response::HTTP_BAD_REQUEST, '', 1);
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Generateresponse(1, $e->getMessage(), Response::HTTP_BAD_REQUEST, '', 1);
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Sendfavouritesold
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : send notification on favourite sold
    | In Params : void
    | Date      : 18th October 2018
    |
     */

    public function Sendfavouritesold($orderId = 0)
    {
        $cart = Cart::where('user_id', $this->user()->id)->first();
        if ($cart) {
            $userName = $this->user()->first_name . ' ' . $this->user()->last_name;
            $ids      = $cart->products->pluck('product_id')->toArray();
            $products = Product::whereIn('id', $ids)->get();
            $send     = $products->each(function ($product, $key) use ($userName, $orderId) {
                $users         = $product->favourited;
                $seller        = $product->user;
                $cProductId    = $product->id;
                $cartOrderList = CartOrder::whereHas('products', function ($query) use ($cProductId) {
                    $query->where('product_id', $cProductId);
                })->get();
                if (!$cartOrderList->isEmpty()) {
                    $cartOrderList->each(function ($order, $oKey) use ($cProductId) {
                        if ($order->total_products > 1) {
                            $delete = CartProduct::where('order_id', $order->id)->where('product_id', $cProductId)->delete();
                            if ($delete) {
                                $recalculate = $order->reCalculate();
                                if ($recalculate) {
                                    $valid = true;
                                }
                            }
                        } else {
                            $carts = Cart::find($order->cart_id);
                            if ($carts->total_orders <= 1) {
                                $deleteProducts = $order->products()->delete();
                                $delete         = $order->delete();
                                $deleteCart     = $carts->delete();
                            } else {
                                $deleteProducts = $order->products()->delete();
                                $delete         = $order->delete();
                                $deleteCart     = true;
                            }
                            if ($delete && $deleteProducts && $deleteCart) {
                                $valid = true;
                            }
                        }

                    });
                }
                $sellerMessage            = 'Your ' . $product->title . ' product has been purchased by ' . $userName . '.';
                $sellerPush               = new \App\UserNotifications;
                $sellerPush->user_id      = $seller->id;
                $sellerPush->reference_id = $orderId;
                $sellerPush->type         = 3; //order
                $sellerPush->text         = $sellerMessage;
                $sellerPush->save();

                $sellerTokens = $seller->deviceTokens->pluck('device_token')->toArray();
                if (!empty($sellerTokens)) {
                    $fireMessage = new Firebase($sellerTokens, $sellerMessage);
                    $fireMessage->sendPush($sellerPush->toArray());
                }
                if (!$users->isEmpty()) {
                    $currentuser = $this->user()->id;
                    $users->each(function ($user, $key) use ($product, $currentuser) {
                        if ($user->id != $currentuser) {
                            $message                    = $product->title . ' product from your favourites got sold.';
                            $notification               = new \App\UserNotifications;
                            $notification->user_id      = $user->id;
                            $notification->reference_id = $product->id;
                            $notification->type         = 2; //product
                            $notification->text         = $message;
                            if ($notification->save()) {
                                $tokens = $user->deviceTokens->pluck('device_token')->toArray();
                                if (!empty($tokens)) {
                                    $firebase = new Firebase($tokens, $message);
                                    $firebase->sendPush($notification->toArray());
                                }
                            }
                        }
                    });
                }

            });
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Createshipment
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to create a new shipment
    | In Params : void
    | Date      : 22nd October 2018
    |
     */

    public function Createshipment(Request $request)
    {
        try {
            \DB::beginTransaction();
            $rules = [
                'order_id' => 'required|exists:orders,id,status,0,seller_id,' . $request->user()->id,
                'height'   => 'required|numeric',
                'width'    => 'required|numeric',
                'weight'   => 'required|numeric|max:30',
                'length'   => 'required|numeric',
            ];
            ## validate request
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                ## implode messages
                $messages   = implode(" & ", $validator->messages()->all());
                $returnData = UtilityController::Generateresponse(0, $messages, Response::HTTP_BAD_REQUEST, '', 1);
            } else {
                ## default return response
                $returnData = UtilityController::Setreturnvariables();

                $sellerAddress = $request->user()->shippingAddress;
                if (!$sellerAddress) {
                    return UtilityController::Generateresponse(0, 'NO_DEFAULT_SHIPPING', Response::HTTP_BAD_REQUEST, '', 1);
                }
                ## get address city and state
                $sellerAddress->city;
                $sellerAddress->state;
                ## get seller shipping address to give canadapost
                $sellerAddress = $this->convertAddressObject($sellerAddress, 3);
                unset($sellerAddress['seller_id']);
                $sellerAddress['seller_phone_number'] = $sellerAddress['seller_mobile_number'];
                ## merge seller address object into request
                $request->merge($sellerAddress);
                $order = Order::find($request->order_id);
                if ($order) {
                    ## if order available, update it.
                    if ($order->update($request->all())) {
                        $valid = true;
                        $order->products;
                        $post     = new CanedaPost();
                        $shipment = $post->createShipment($order->toArray());
                        if (!empty($shipment)) {
                            $order->shipment_id   = $shipment['shipment-id'];
                            $order->ship_track_id = $shipment['tracking-pin'];
                            $order->status        = 1;
                            if (!$order->save()) {
                                $valid = false;
                            }
                        } else {
                            $valid = false;
                        }

                        if ($valid) {
                            \DB::commit();
                            $returnData = UtilityController::Generateresponse(1, 'SHIPMENT_GENERATED', Response::HTTP_OK, $order, 1);
                        }

                    }

                }

            }
            return $returnData;
        } catch (\Exception $e) {
            \DB::rollback();
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getorders
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get user orders
    | In Params : void
    | Date      : 17th October 2018
    |
     */

    public function Getorders(Request $request)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $orders     = Order::with('products.images', 'seller')->where('user_id', $this->user()->id)->get();
            $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', Response::HTTP_OK, $orders, 1);
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getsellerorders
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get seller orders
    | In Params : void
    | Date      : 17th October 2018
    |
     */

    public function Getsellerorders(Request $request)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $orders     = Order::with('products.images', 'customer')->where('seller_id', $this->user()->id)->get();
            $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', Response::HTTP_OK, $orders, 1);
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }
    /*
    |--------------------------------------------------------------------------
    | GetAttributes
    |--------------------------------------------------------------------------
    |
    | Author    : Ramya Iyer <ramya@creolestudios.com>
    | Purpose   : to get attributes
    | In Params : void
    | Date      : 23th October 2018
    |
     */

    public function Getallattributes()
    {

        try {
            $attributes = Attributes::with('subAttributes.dependant')->get();
            $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', Response::HTTP_OK, $attributes, 1);
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Deleteproduct
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to delete product
    | In Params : void
    | Date      : 23rd October 2018
    |
     */

    public function Deleteproduct($productId)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $products   = Product::where('id', $productId)->delete();
            $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', Response::HTTP_OK, $products, 1);
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    |
    |--------------------------------------------------------------------------
    |
    | Author    : Ramya Iyer <ramya@creolestudios.com>
    | Purpose   : Addeditattributes
    | In Params : void
    | Date      : 23th October 2018
    |
     */

    public function Addeditattributes(Request $request)
    {

        try {
            $inputData = $request->all();
            $rules     = [
                'name.*'          => 'required|exists:orders,id,status,0',
                'hex.*'           => 'sometimes|numeric',
                'type.*'          => 'sometimes|numeric',
                'id.*'            => 'sometimes|exists:sub_attributes,id',
                'attribute_id.*'  => 'required|exists:attributes,id',
                'referanced_id.*' => 'sometimes|exists:sub_attributes,id',
            ];
            ## validate request
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                ## implode messages
                $messages   = implode(" & ", $validator->messages()->all());
                $returnData = UtilityController::Generateresponse(0, $messages, Response::HTTP_BAD_REQUEST, '', 1);
            } else {
                $result = UtilityController::Createupdatearray($request->all(), 'SubAttributes');
                if ($result) {
                    $returnData = UtilityController::Generateresponse(1, 'ATTRIBUTE_MODIFIED', Response::HTTP_OK, '', 1);
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cancelorder
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to cancel order
    | In Params : void
    | Date      : 25th October 2018
    |
     */

    public function Cancelorder(Request $request)
    {
        try {
            \DB::beginTransaction();
            $rules = [
                'order_id' => 'required|exists:orders,id,deleted_at,NULL,status,0,seller_id,' . $request->user()->id,
            ];
            ## validate request
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                ## implode messages
                $messages   = implode(" & ", $validator->messages()->all());
                $returnData = UtilityController::Generateresponse(0, $messages, Response::HTTP_BAD_REQUEST, '', 1);
            } else {
                $returnData    = UtilityController::Setreturnvariables();
                $order         = Order::with('products')->find($request->order_id);
                $productIds    = $order->products->pluck('product_id')->toArray();
                $resetProducts = Product::whereIn('id', $productIds)->update(['status' => 1]);
                if ($resetProducts) {
                    $stripeKey = Config('services.stripe.key');
                    \Stripe\Stripe::setApiKey($stripeKey);
                    $refund = \Stripe\Refund::create([
                        "charge" => $order->transaction_id,
                    ]);
                    if ($refund) {
                        $order->refund_id = $refund->id;
                        $order->status    = 2;
                        if ($request->has('cancel_reason')) {
                            $order->cancel_reason = $request->cancel_reason;
                        }
                        if ($order->save()) {
                            \DB::commit();
                            $returnData = UtilityController::Generateresponse(1, 'ORDER_CANCELLED', Response::HTTP_OK, '', 1);
                        }
                    }
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            \DB::rollback();
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Generateresponse(1, $e->getMessage(), Response::HTTP_BAD_REQUEST, '', 1);
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getorderdetail
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get details of order
    | In Params : void
    | Date      : 25th October 2018
    |
     */

    public function Getorderdetail(Request $request)
    {
        try {
            $rules = [
                'order_id' => 'required|exists:orders,id,deleted_at,NULL',
            ];
            ## validate request
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                ## implode messages
                $messages   = implode(" & ", $validator->messages()->all());
                $returnData = UtilityController::Generateresponse(0, $messages, Response::HTTP_BAD_REQUEST, '', 1);
            } else {
                $order      = Order::with(['products.images', 'seller', 'customer'])->find($request->order_id);
                $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', Response::HTTP_OK, $order, 1);
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getpendingorders
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get pending order lists with filters
    | In Params : void
    | Date      : 29th October 2018
    |
     */

    public function Getpendingorders(Request $request)
    {

        try {
            $returnData = UtilityController::Setreturnvariables();

            $orders = Order::where('status', '!=', 2);
            if ($request->has('from') && $request->has('to')) {
                $from = new \Carbon\Carbon($request->from);
                $to   = new \Carbon\Carbon($request->to);
                $from = $from->startOfDay();
                $to   = $to->endOfDay();

                $orders = $orders->whereBetween('created_at', [$from, $to]);
            }
            if ($request->has('page')) {
                $page = $request->page;
            } else {
                $page = 1;
            }

            if ($request->has('search')) {
                $search = $request->search;
                $orders = $orders->whereHas('customer', function ($query) use ($search) {
                    $query->where('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            }

            $orders = $orders->with(['products.product.images', 'seller', 'customer'])->pending()->orderBy('created_at', 'desc')->get();
            if ($request->has('sort_by')) {
                if ($request->sort_type == 1) {
                    if ($request->sort_by == 'order') {
                        $orders = $orders->sortBy('id')->values();
                    } elseif ($request->sort_by == 'orderPlaced') {
                        $orders = $orders->sortBy('created_at')->values();
                    } elseif ($request->sort_by == 'orderSent') {
                        $orders = $orders->sortBy('updated_at')->values();
                    } elseif ($request->sort_by == 'totalCost') {
                        $orders = $orders->sortBy('total_cost')->values();
                    }

                } elseif ($request->sort_type == 2) {
                    if ($request->sort_by == 'order') {
                        $orders = $orders->sortByDesc('id')->values();
                    } elseif ($request->sort_by == 'orderPlaced') {
                        $orders = $orders->sortByDesc('created_at')->values();
                    } elseif ($request->sort_by == 'orderSent') {
                        $orders = $orders->sortByDesc('updated_at')->values();
                    } elseif ($request->sort_by == 'totalCost') {
                        $orders = $orders->sortByDesc('total_cost')->values();
                    }
                }
            }
            $pagination = UtilityController::Custompaginate($orders, $this->perPage, $page, ['path' => url('/admin/Getpendingorders')])->toArray();
            $returnData = ['status' => 1, 'status_code' => 200, 'message' => 'Success!'] + $pagination;
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getdeliveredorders
    |--------------------------------------------------------------------------
    |
    | Author    : Ramya Iyer <ramya@creolestudios.com>
    | Purpose   : to get list of delivered orders with filters
    | In Params : void
    | Date      : 5th November 2018
    |
     */

    public function Getdeliveredorders(Request $request)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            if ($request->has('page')) {
                $page = $request->page;
            } else {
                $page = 1;
            }
            $orders = Order::delivered();
            if ($request->has('from') && $request->has('to')) {
                $from = new \Carbon\Carbon($request->from);
                $to   = new \Carbon\Carbon($request->to);
                $from = $from->startOfDay()->toDateTimeString();
                $to   = $to->endOfDay()->toDateTimeString();

                $orders = $orders->whereBetween('created_at', [$from, $to]);
            }
            if ($request->has('search')) {
                $search = $request->search;
                $orders = $orders->whereHas('customer', function ($query) use ($search) {
                    $query->where('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            }

            ## get dependancies
            $orders = $orders->with(['products.product.images', 'seller', 'customer'])->orderBy('created_at', 'desc')->get();

            if ($request->has('sort_by')) {
                if ($request->sort_type == 1) {
                    if ($request->sort_by == 'order') {
                        $orders = $orders->sortBy('id')->values();
                    } elseif ($request->sort_by == 'orderPlaced') {
                        $orders = $orders->sortBy('created_at')->values();
                    } elseif ($request->sort_by == 'orderSent') {
                        $orders = $orders->sortBy('updated_at')->values();
                    } elseif ($request->sort_by == 'totalCost') {
                        $orders = $orders->sortBy('total_cost')->values();
                    }

                } elseif ($request->sort_type == 2) {
                    if ($request->sort_by == 'order') {
                        $orders = $orders->sortByDesc('id')->values();
                    } elseif ($request->sort_by == 'orderPlaced') {
                        $orders = $orders->sortByDesc('created_at')->values();
                    } elseif ($request->sort_by == 'orderSent') {
                        $orders = $orders->sortByDesc('updated_at')->values();
                    } elseif ($request->sort_by == 'totalCost') {
                        $orders = $orders->sortByDesc('total_cost')->values();
                    }
                }
            }
            $pagination = UtilityController::Custompaginate($orders, $this->perPage, $page, ['path' => url('/admin/Getdeliveredorders')])->toArray();
            $returnData = ['status' => 1, 'status_code' => 200, 'message' => 'Success!'] + $pagination;
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getdisputes
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get list of raised dispute
    | In Params : void
    | Date      : 23rd November 2018
    |
     */

    public function Getdisputes(Request $request)
    {
        try {

            ## init Order products class with conditional scopes
            $disputes = \App\OrderProducts::disputes();

            if ($request->has('from') && $request->has('to')) {
                $from = new \Carbon\Carbon($request->from);
                $to   = new \Carbon\Carbon($request->to);
                $from = $from->startOfDay();
                $to   = $to->endOfDay();
                ## if request has such fields, filter them
                $disputes = $disputes->whereDate('dispute_reported', '>=', $from);
                $disputes = $disputes->whereDate('dispute_reported', '<=', $to);
            }
            if ($request->has('search')) {
                $search   = $request->search;
                $disputes = $disputes->whereHas('order.customer', function ($query) use ($search) {
                    $query->where('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            }

            ## get dependancies
            $disputes = $disputes->with('product.images', 'product.attributes', 'reason', 'order.customer', 'order.seller', 'disputeImages');

            ## paginate record
            $disputes = $disputes->paginate($this->perPage)->toArray();

            ## generate response array
            $returnData = ['status' => 1, 'status_code' => 200, 'message' => 'Success!'] + $disputes;
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getcancelledorders
    |--------------------------------------------------------------------------
    |
    | Author    : Ramya Iyer <ramya@creolestudios.com>
    | Purpose   : to get list of cancelled orders with filters
    | In Params : void
    | Date      : 13th November 2018
    |
     */

    public function Getcancelledorders(Request $request)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            if ($request->has('page')) {
                $page = $request->page;
            } else {
                $page = 1;
            }
            $orders = Order::where('status', 2);
            if ($request->has('from') && $request->has('to')) {
                $from   = new \Carbon\Carbon($request->from);
                $to     = new \Carbon\Carbon($request->to);
                $from   = $from->startOfDay();
                $to     = $to->endOfDay();
                $orders = $orders->whereBetween('created_at', [$from, $to]);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $orders = $orders->whereHas('customer', function ($query) use ($search) {
                    $query->where('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            }
            $orders = $orders->with(['products.product.images', 'seller', 'customer'])->orderBy('created_at', 'desc')->get();
            if ($request->has('sort_by')) {
                if ($request->sort_type == 1) {
                    if ($request->sort_by == 'order') {
                        $orders = $orders->sortBy('id')->values();
                    } elseif ($request->sort_by == 'orderPlaced') {
                        $orders = $orders->sortBy('created_at')->values();
                    } elseif ($request->sort_by == 'orderSent') {
                        $orders = $orders->sortBy('updated_at')->values();
                    } elseif ($request->sort_by == 'totalCost') {
                        $orders = $orders->sortBy('total_cost')->values();
                    }

                } elseif ($request->sort_type == 2) {
                    if ($request->sort_by == 'order') {
                        $orders = $orders->sortByDesc('id')->values();
                    } elseif ($request->sort_by == 'orderPlaced') {
                        $orders = $orders->sortByDesc('created_at')->values();
                    } elseif ($request->sort_by == 'orderSent') {
                        $orders = $orders->sortByDesc('updated_at')->values();
                    } elseif ($request->sort_by == 'totalCost') {
                        $orders = $orders->sortByDesc('total_cost')->values();
                    }
                }
            }
            $pagination = UtilityController::Custompaginate($orders, $this->perPage, $page, ['path' => url('/admin/Getcancelledorders')])->toArray();
            $returnData = ['status' => 1, 'status_code' => 200, 'message' => 'Success!'] + $pagination;
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getmultiplecoupons
    |--------------------------------------------------------------------------
    |
    | Author    : Ramya Iyer <ramya@creolestudios.com>
    | Purpose   : get listing of multiple usage coupons
    | In Params : void
    | Date      : 5th November 2018
    |
     */

    public function Getmultiplecoupons(Request $request)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            if ($request->has('page')) {
                $page = $request->page;
            } else {
                $page = 1;
            }
            $coupons = Coupon::multiple();
            ## search string. if user typed anything
            if ($request->has('search')) {
                $search = $request->search;
                ## all products
                $coupons = $coupons->where('name', 'like', '%' . $search . '%')->orWhere('code', 'like', '%' . $search . '%');
            }
            $coupons    = $coupons->get();
            $pagination = UtilityController::Custompaginate($coupons, $this->perPage, $page, ['path' => url('/admin/Getmultiplecoupons')])->toArray();
            $returnData = ['status' => 1, 'status_code' => 200, 'message' => 'Success!'] + $pagination;
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getsinglecoupon
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get records of single coupon
    | In Params : void
    | Date      : 6th November 2018
    |
     */

    public function Getsinglecoupon(Request $request)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            if ($request->has('page')) {
                $page = $request->page;
            } else {
                $page = 1;
            }
            $coupons = Coupon::single();
            ## search string. if user typed anything
            if ($request->has('search')) {
                $search = $request->search;
                ## all products
                $coupons = $coupons->where('code', 'like', '%' . $search . '%')->orWhere(function ($query) use ($search) {
                    $query->whereHas('redimmer', function ($query) use ($search) {
                        $query->where('first_name', 'like', '%' . $search . '%')
                            ->orWhere('last_name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    });
                });
            }
            $coupons    = $coupons->with('redimmer')->get();
            $pagination = UtilityController::Custompaginate($coupons, $this->perPage, $page, ['path' => url('/admin/Getmultiplecoupons')])->toArray();
            $returnData = ['status' => 1, 'status_code' => 200, 'message' => 'Success!'] + $pagination;
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Getcoupondata
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get details of coupon
    | In Params : void
    | Date      : 6th November 2018
    |
     */

    public function Getcoupondata($id)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            if ($id) {
                $details = Coupon::find($id);
                if ($details) {
                    $returnData = UtilityController::Generateresponse(1, 'EMPTY_MESSAGE', Response::HTTP_OK, $details, 1);
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Addeditmultiplecoupons
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to add or edit multiple coupons
    | In Params : void
    | Date      : 6th November 2018
    |
     */

    public function Addeditmultiplecoupons(Request $request)
    {
        try {
            $returnData = UtilityController::ValidationRules($request->all(), 'Coupon');
            if (!$returnData['status']) {
                return $returnData;
            } else {
                if ($request->coupon_type == 1) {
                    $code = UtilityController::generateString(8);
                    $request->merge(['code' => $code]);
                }
                $action = UtilityController::Createorupdate($request->all(), 'Coupon');
                if ($action) {
                    $returnData = UtilityController::Generateresponse(1, 'COUPON_MODIFIED', Response::HTTP_OK, '', 1);
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }
    /*
    |--------------------------------------------------------------------------
    | GetPayments
    |--------------------------------------------------------------------------
    |
    | Author    : Ramya Iyer <ramya@creolestudios.com>
    | Purpose   : to get list of payments
    | In Params : void
    | Date      : 12th November 2018
    |
     */

    public function Getpayments(Request $request)
    {
        try {

            $returnData = UtilityController::Setreturnvariables();
            // $payment    = Order::where('status', '=', 4)->with('customer')->orderBy('created_at', 'desc')->get();
            if ($request->has('page')) {
                $page = $request->page;
            } else {
                $page = 1;
            }
            $payment = \App\OrderProducts::payble();
            if ($request->has('from') && $request->has('to')) {
                $from    = new \Carbon\Carbon($request->from);
                $to      = new \Carbon\Carbon($request->to);
                $from    = $from->startOfDay();
                $to      = $to->endOfDay();
                $payment = $payment->whereBetween('created_at', [$from, $to]);
            }
            if ($request->has('search')) {
                $search  = $request->search;
                $payment = $payment->whereHas('order.customer', function ($query) use ($search) {
                    $query->where('first_name', 'like', '%' . $search . '%')
                        ->orWhere('last_name', 'like', '%' . $search . '%');
                })
                    ->orWhereHas('order', function ($query) use ($search) {
                        $query->where('transaction_id', 'like', '%' . $search . '%')->where('status', 4)->where('has_dispute', 0);
                    });
            }

            $payment = $payment->with('order.customer')->orderBy('created_at', 'desc')->get();

            if ($request->has('sort_by')) {
                if ($request->sort_type == 1) {
                    if ($request->sort_by == 'payment') {
                        $payment = $payment->sortBy(function ($item) {
                            return $item->order->id;
                        })->values();
                    } elseif ($request->sort_by == 'date') {
                        $payment = $payment->sortBy('created_at')->values();
                    } elseif ($request->sort_by == 'user') {
                        $payment = $payment->sortBy(function ($item) {
                            return $item->order->seller_id;
                        })->values();
                    } elseif ($request->sort_by == 'totalCost') {
                        $payment = $payment->sortBy('seller_amount')->values();
                    } elseif ($request->sort_by == 'paid') {
                        $payment = $payment->sortBy('is_paid')->values();
                    }

                } elseif ($request->sort_type == 2) {
                    if ($request->sort_by == 'payment') {
                        $payment = $payment->sortByDesc(function ($item) {
                            return $item->order->id;
                        })->values();
                    } elseif ($request->sort_by == 'date') {
                        $payment = $payment->sortByDesc('created_at')->values();
                    } elseif ($request->sort_by == 'user') {
                        $payment = $payment->sortByDesc(function ($item) {
                            return $item->order->seller_id;
                        })->values();
                    } elseif ($request->sort_by == 'totalCost') {
                        $payment = $payment->sortByDesc('seller_amount')->values();
                    } elseif ($request->sort_by == 'paid') {
                        $payment = $payment->sortByDesc('is_paid')->values();
                    }
                }
            }
            $pagination = UtilityController::Custompaginate($payment, $this->perPage, $page, ['path' => url('/admin/Getpayments')])->toArray();
            $returnData = ['status' => 1, 'status_code' => 200, 'message' => 'Success!'] + $pagination;
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | paidPayments
    |--------------------------------------------------------------------------
    |
    | Author    : Ramya Iyer <ramya@creolestudios.com>
    | Purpose   : paid payments
    | In Params : void
    | Date      : 12th November 2018
    |
     */

    public function paidPayments(Request $request)
    {
        try {
            $returnData         = UtilityController::Setreturnvariables();
            $payment            = \App\OrderProducts::find($request->id)->toArray();
            $payment['is_paid'] = 1;
            $result             = UtilityController::Makemodelobject($payment, 'OrderProducts', '', $request->id);
            if ($result) {
                $order = \App\Order::find($payment['order_id']);
                $system              = new SystemNotifications;
                $system->user_id     = $order->seller_id;
                $system->description = $order->seller->first_name . ' ' . $order->seller->last_name . " is been paid";
                $system->type        = 4;
                $system->save();
                $returnData = UtilityController::Generateresponse(1, 'PAYMENTS_PAID', Response::HTTP_OK, '', 1);
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }
    /*
    |--------------------------------------------------------------------------
    | Getshippinglabel
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to get shipping label by mail
    | In Params : void
    | Date      : 14th November 2018
    |
     */

    public function Getshippinglabel(Request $request)
    {
        try {
            $rules = [
                'order_id' => 'required|exists:orders,id,deleted_at,NULL,status,1,seller_id,' . $request->user()->id,
            ];
            ## validate request
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                ## implode messages
                $messages   = implode(" & ", $validator->messages()->all());
                $returnData = UtilityController::Generateresponse(0, $messages, Response::HTTP_BAD_REQUEST, '', 1);
            } else {
                $returnData = UtilityController::Setreturnvariables();
                $shipment   = Order::find($request->order_id);
                if ($shipment->packing_sleep && $shipment->packing_sleep != 'null' && $shipment->packing_sleep != null) {
                    $filePath = Storage::disk('shipping_labels')->path($shipment->packing_sleep);
                    if ($filePath) {
                        Mail::later(\Carbon\Carbon::now(), new ShippingLabel($request->user()->email, $filePath));
                        $returnData = UtilityController::Generateresponse(1, 'SLEEP_GENERATED', Response::HTTP_OK, '', 1);
                    }
                } else {
                    $post        = new CanedaPost();
                    $credentials = $post->getLabelId($shipment->shipment_id);
                    if (!empty($credentials)) {
                        $record = $post->getArtifact($credentials);
                        if (!empty($record)) {
                            Mail::later(\Carbon\Carbon::now(), new ShippingLabel($request->user()->email, $record['path']));
                            $returnData = UtilityController::Generateresponse(1, 'SLEEP_GENERATED', Response::HTTP_OK, '', 1);
                        }
                    }
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Downloadsleep
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to download packing sleep
    | In Params : void
    | Date      : 7th December 2018
    |
     */

    public function Downloadsleep($orderId)
    {
        try {
            $returnData = UtilityController::Setreturnvariables();
            $referrar   = $_SERVER["HTTP_REFERER"];
            $order      = Order::find($orderId);
            if ($order) {
                if ($order->packing_sleep && $order->packing_sleep != 'null' && $order->packing_sleep != "0" && $order->packing_sleep != null) {
                    $sleep    = $order->packing_sleep;
                    $filePath = Storage::disk('shipping_labels')->path($sleep);
                    if ($filePath) {
                        return response()->download($filePath);
                    }
                }
            }
            return redirect()->to($referrar);
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return redirect()->to($referrar);
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Raisedispute
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to raise new dispute
    | In Params : void
    | Date      : 19th November 2018
    |
     */

    public function Raisedispute(Request $request)
    {
        try {
            \DB::beginTransaction();
            $rules = [
                'product_id'       => 'required|array',
                'product_id.*'     => 'exists:order_products,id,has_dispute,0,is_paid,0',
                'reason_id'        => 'required|exists:common_messages,id,type,1',
                'dispute_comment'  => 'sometimes|required',
                'dispute_images.*' => 'image|mimes:jpg,png,jpeg',
            ];
            ## validate request
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                ## implode messages
                $messages   = implode(" & ", $validator->messages()->all());
                $returnData = UtilityController::Generateresponse(0, $messages, Response::HTTP_BAD_REQUEST, '', 1);
            } else {
                $returnData = UtilityController::Setreturnvariables();
                $valid      = true;
                foreach ($request->product_id as $key => $productId) {
                    $product                   = \App\OrderProducts::find($productId);
                    $product->has_dispute      = 1;
                    $product->dispute_reported = Carbon::now();
                    $product->reason_id        = $request->reason_id;
                    if ($request->has('dispute_comment')) {
                        $product->dispute_comment = $request->dispute_comment;
                    }
                    if ($product->save()) {
                        if ($request->hasFile('dispute_images')) {
                            $imageResponse = $this->uploadDisputeImages($request->dispute_images);
                            if (!empty($imageResponse)) {
                                $createImages = $product->disputeImages()->createMany($imageResponse);
                                if (!$createImages) {
                                    $valid = false;
                                }
                            }
                        }
                        $system              = new SystemNotifications;
                        $system->user_id     = $request->user()->id;
                        $system->description = $request->user()->first_name . ' ' . $request->user()->last_name . " raised a dispute";
                        $system->type        = 3;
                        $system->save();
                    } else {
                        $valid = false;
                    }
                }
                if ($valid) {
                    \DB::commit();
                    $returnData = UtilityController::Generateresponse(1, 'DISPUTE_RAISED', Response::HTTP_OK, '', 1);
                }
            }
            return $returnData;
        } catch (\Exception $e) {
            \DB::rollback();
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Resolvedispute
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to resolve dispute accordingly
    | In Params : void
    | Date      : 26th November 2018
    |
     */

    public function Resolvedispute(Request $request)
    {
        try {
            \DB::beginTransaction();
            $rules = [
                'id'              => 'required|exists:order_products,id,has_dispute,1,is_paid,0',
                'reason_id'       => 'required|exists:common_messages,id,type,1',
                'dispute_comment' => 'sometimes|required',
                'resolve'         => 'required|integer|min:1|max:2',
                'resolved_amount' => 'required_if:resolve,==,2',
            ];
            ## validate request
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                ## implode messages
                $messages   = implode(" & ", $validator->messages()->all());
                $returnData = UtilityController::Generateresponse(0, $messages, Response::HTTP_BAD_REQUEST, '', 1);
            } else {
                $returnData   = UtilityController::Setreturnvariables();
                $product      = \App\OrderProducts::find($request->id);
                $sellerObject = [];
                $buyerObject  = [];
                $valid        = true;
                if ($request->resolve == 2) {
                    $amountResolved = $request->resolved_amount;
                    if ($request->price != $amountResolved) {
                        ## Resolved in favour of Both
                        $request->merge(['resolved' => 3]);
                        $commision = 100 - $request->product['commision'];
                        ## generate seller payment object
                        $sellerObject['amount']  = ($amountResolved * $commision) / 100;
                        $sellerObject['type']    = 2;
                        $sellerObject['percent'] = ($amountResolved * 100) / $request->price;

                        ## Generate buyer payment object
                        $buyerObject['amount']  = $request->price - $amountResolved;
                        $buyerObject['type']    = 1;
                        $buyerObject['percent'] = ($buyerObject['amount'] * 100) / $request->price;
                        if ($product) {
                            if (!$product->payments()->create($buyerObject) || !$product->payments()->create($sellerObject)) {
                                $valid = false;
                            }
                        }
                    } else {
                        ## resolved in favour of seller
                        $request->merge(['resolved' => 2]);
                        $sellerObject['amount']  = $request->seller_amount;
                        $sellerObject['type']    = 2;
                        $sellerObject['percent'] = 100;
                        if ($product) {
                            if (!$product->payments()->create($sellerObject)) {
                                $valid = false;
                            }
                        }

                    }
                } else {
                    ## resolved in favour of buyer
                    $request->merge(['resolved' => 1]);
                    $buyerObject['amount']  = $request->price;
                    $buyerObject['type']    = 1;
                    $buyerObject['percent'] = 100;
                    if ($product) {
                        if (!$product->payments()->create($buyerObject)) {
                            $valid = false;
                        }
                    }
                }
                if ($valid) {
                    if ($product) {
                        $request->merge(['is_paid' => 1]);
                        if ($product->update($request->all())) {
                            \DB::commit();
                            $returnData = UtilityController::Generateresponse(1, 'DISPUTE_RESOLVED', Response::HTTP_OK, '', 1);
                        }
                    }
                }

            }

            return $returnData;
        } catch (\Exception $e) {
            \DB::rollback();
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return UtilityController::Setreturnvariables();
        }

    }

    /*
    |--------------------------------------------------------------------------
    | deleteImage
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to delete image
    | In Params : void
    | Date      : 15th October 2018
    |
     */

    private function deleteImage(\App\ProductImages $image)
    {
        try {
            $directory = "product_images";
            $fileName  = $image->name;

            if (Storage::disk($directory)->has($fileName)) {
                Storage::delete("$directory/$fileName");
            }
            return true;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return false;
        }

    }

    /*
    |--------------------------------------------------------------------------
    | convertAddressObject
    |--------------------------------------------------------------------------
    |
    | Author    : Bhargav Bhanderi <bhargav@creolestudios.com>
    | Purpose   : to convert address object
    | In Params : void
    | Date      : 17th October 2018
    | Types     : 1=> Shipping, 2=> Billing
    |
     */

    private function convertAddressObject($address, $type = 1)
    {
        try {
            $instance    = $type == 1 ? 's_' : ($type == 2 ? 'b_' : 'seller_');
            $newResponse = [];
            foreach ($address->toArray() as $key => $value) {
                if ($key == 'city') {
                    if (!empty($value)) {
                        $newResponse[$instance . $key] = $value['name'];
                    } else {
                        $newResponse[$instance . $key] = $address->state['name'];
                    }
                } elseif ($key == 'state' && !empty($value)) {
                    $newResponse[$instance . $key] = $value['state_code'];
                } else {
                    $newResponse[$instance . $key] = $value;
                }
            }
            return $newResponse;
        } catch (\Exception $e) {
            $sendExceptionMail = UtilityController::Sendexceptionmail($e);
            //Code to send mail ends here
            return [];
        }

    }
}
