<?php

class Entry extends Eloquent {

    protected $hidden = array('body', 'body_rendered', 'identifier_id', 'license_id', 'user_id');

    public function teams()
    {
        return $this->belongsToMany('Team')->withPivot('removed_from_team')->withTimestamps();
    }

    public function user()
    {
        return $this->belongsTo('User');
    }

    public function identifier()
    {
        return $this->belongsTo('Identifier');
    }

    public function license()
    {
        return $this->belongsTo('License');
    }
    
    public function votes()
    {
        return $this->hasMany('Vote')->withTimestamps();
    }
}
