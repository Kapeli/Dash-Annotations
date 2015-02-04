<?php namespace App;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Entry extends Eloquent {

    protected $hidden = array('body', 'body_rendered', 'identifier_id', 'license_id', 'user_id');

    public function teams()
    {
        return $this->belongsToMany('App\Team')->withPivot('removed_from_team')->withTimestamps();
    }

    public function user()
    {
        return $this->belongsTo('App\User');
    }

    public function identifier()
    {
        return $this->belongsTo('App\Identifier');
    }

    public function license()
    {
        return $this->belongsTo('App\License');
    }
    
    public function votes()
    {
        return $this->hasMany('App\Vote')->withTimestamps();
    }
}
