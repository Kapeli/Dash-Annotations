<?php 

class Vote extends Eloquent {

    public function user()
    {
        return $this->belongsTo('User')->withTimestamps();
    }    

    public function entry()
    {
        return $this->belongsTo('Entry')->withTimestamps();
    }

}
