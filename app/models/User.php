<?php namespace App;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;

class User extends Model implements AuthenticatableContract, CanResetPasswordContract {

    use Authenticatable, CanResetPassword;

	protected $hidden = array('password', 'remember_token');

    public function teams()
    {
        return $this->belongsToMany('App\Team')->withPivot('role')->withTimestamps();
    }

    public function entries()
    {
        return $this->hasMany('App\Entry')->withTimestamps();
    }

    public function votes()
    {
        return $this->hasMany('App\Vote')->withTimestamps();
    }
}
