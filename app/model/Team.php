<?php namespace App;

use Illuminate\Database\Eloquent\Model;

class Team extends Model {

    protected $hidden = array('access_key');

    public function users()
    {
        return $this->belongsToMany('App\User')->withPivot('role')->withTimestamps();
    }

    public function owner()
    {
        return $this->users()->where('role', '=', 'owner')->first();
    }

    public function entries()
    {
        return $this->belongsToMany('App\Entry')->withPivot('removed_from_team')->withTimestamps();
    }
    
}
