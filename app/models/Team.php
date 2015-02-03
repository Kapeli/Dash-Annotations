<?php 

class Team extends Eloquent {

    protected $hidden = array('access_key');

    public function users()
    {
        return $this->belongsToMany('User')->withPivot('role')->withTimestamps();
    }

    public function owner()
    {
        return $this->users()->where('role', '=', 'owner')->first();
    }

    public function entries()
    {
        return $this->belongsToMany('Entry')->withPivot('removed_from_team')->withTimestamps();
    }
    
}
