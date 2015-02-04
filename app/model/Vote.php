<?php namespace App;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Vote extends Eloquent {

    public function user()
    {
        return $this->belongsTo('App\User')->withTimestamps();
    }    

    public function entry()
    {
        return $this->belongsTo('App\Entry')->withTimestamps();
    }

}
