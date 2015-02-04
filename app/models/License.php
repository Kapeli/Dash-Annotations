<?php namespace App;

use Illuminate\Database\Eloquent\Model as Eloquent;

class License extends Eloquent {

    protected $hidden = array('license');

    public function entries()
    {
        return $this->hasMany('App\Entry')->withTimestamps();
    }
}
