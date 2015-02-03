<?php 

class License extends Eloquent {

    protected $hidden = array('license');

    public function entries()
    {
        return $this->hasMany('Entry')->withTimestamps();
    }
}
