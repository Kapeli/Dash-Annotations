<?php

use Illuminate\Auth\UserTrait;
use Illuminate\Auth\UserInterface;
use Illuminate\Auth\Reminders\RemindableTrait;
use Illuminate\Auth\Reminders\RemindableInterface;

class User extends Eloquent implements UserInterface, RemindableInterface {

	use UserTrait, RemindableTrait;

	protected $hidden = array('password', 'remember_token');

    public function teams()
    {
        return $this->belongsToMany('Team')->withPivot('role')->withTimestamps();
    }

    public function entries()
    {
        return $this->hasMany('Entry')->withTimestamps();
    }

    public function votes()
    {
        return $this->hasMany('Vote')->withTimestamps();
    }
}
