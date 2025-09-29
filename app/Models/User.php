<?php namespace App;

use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Notifications\Notifiable;
use App\Notifications\ResetPasswordNotification;

class User extends Model implements AuthenticatableContract, CanResetPasswordContract {

    use Authenticatable, CanResetPassword, Notifiable;

    /**
     * Send the password reset notification.
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPasswordNotification($token));
    }

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
