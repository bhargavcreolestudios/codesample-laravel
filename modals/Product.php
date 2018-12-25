<?php
//###############################################################
//Model Name : Product
//Author : Bhargav Bhanderi <bhargav@creolestudios.com>
//table : products
//Date : 20th August 2018
//###############################################################
namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tymon\JWTAuth\Facades\JWTAuth;

class Product extends Model
{
    use SoftDeletes;
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'products';

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['created_at', 'updated_at', 'deleted_at'];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = ['deleted_at', 'pivot'];

    /**
     * The attributes that should be appended to object.
     *
     * @var array
     */
    protected $appends = ['is_fav', 'in_cart'];

    /**
     * Fields that can be mass assigned.
     *
     * @var array
     */
    protected $fillable = ['user_id', 'title', 'description', 'price', 'commision', 'status', 'postal_code'];

    /**
     * The list of validation rules.
     *
     * @var array
     */
    public $rules = [
        'title'           => 'required',
        'description'     => 'required',
        'price'           => 'required|numeric',
        'product_image.*' => 'image|mimes:jpg,png,jpeg',
        'product_image'   => 'required',
        'attribs'         => 'required|array',
        'commision'       => 'required',
        'attribs.*'       => 'exists:sub_attributes,id',
        'commision'       => 'required',
    ];

    /**
     * Product belongs to many (many-to-many) Attribute.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function attributes()
    {
        return $this->belongsToMany('App\SubAttributes', 'product_attributes', 'product_id', 'sub_attribute_id')->withTimestamps();
    }

    /**
     * Product belongs to many (many-to-many) favourited.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function favourited()
    {
        return $this->belongsToMany('App\User', 'user_favourites', 'product_id', 'user_id')->withTimestamps();
    }

    /**
     * Product has many Images.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function images()
    {
        return $this->hasMany('App\ProductImages', 'product_id');
    }

    /**
     * Product has one Image.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function image()
    {
        return $this->hasOne('App\ProductImages', 'product_id');
    }

    /**
     * Accessor for is_fav attribute.
     *
     * @return returnType
     */
    public function getIsFavAttribute($value)
    {
        $user   = JWTAuth::toUser();
        $exists = $user->favourites->contains($this->id);
        return $exists;
    }

    /**
     * Accessor for in_cart attribute.
     *
     * @return returnType
     */
    public function getInCartAttribute($value)
    {
        $user = JWTAuth::toUser();
        if ($user) {
            $userCart = Cart::where('user_id', $user->id)->first();
            if ($userCart) {
                $productId = $this->id;
                $exists    = CartOrder::where('cart_id', $userCart->id)->whereHas('products', function ($query) use ($productId) {
                    $query->where('product_id', $productId);
                })->first();
                if ($exists) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Product belongs to User.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo("App\User", 'user_id');
    }

    /**
     * Query scope details.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDetails($query)
    {
        return $query->with(['user', 'attributes', 'images', 'comments.user', 'comments.subComments.user']);
    }

    /**
     * Product has many Comments.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function comments()
    {
        return $this->hasMany('App\ProductComments', 'product_id');
    }

    /**
     * Query scope isActive.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeIsActive($query)
    {
        return $query->where('status', 1);
    }

    /**
     * Query scope notMe.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotMe($query, $id = 0)
    {
        return $query->where('user_id', '!=', $id);
    }

    /**
     * Product has one buyer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */

    public function buyers()
    {
        // hasOne(RelatedModel, foreignKeyOnRelatedModel = product_id, localKey = id)
        return $this->hasOne('App\OrderProducts', 'product_id')->with('orderPro');
    }

}
