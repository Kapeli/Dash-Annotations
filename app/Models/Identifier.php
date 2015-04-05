<?php namespace App;

use Illuminate\Database\Eloquent\Model as Eloquent;

function remove_prefix($prefix, $string)
{
    if(substr(strtolower($string), 0, strlen($prefix)) == strtolower($prefix)) 
    {
        return substr($string, strlen($prefix));
    } 
    return $string;
}

class Identifier extends Eloquent {

    public static function IdentifierFromDictionary($dict)
    {
        $identifier = new Identifier;
        $identifier->docset_name = $dict['docset_name'];
        $identifier->docset_filename = $dict['docset_filename'];
        $identifier->docset_platform = $dict['docset_platform'];
        $identifier->docset_bundle = $dict['docset_bundle'];
        $identifier->docset_version = $dict['docset_version'];
        $identifier->page_path = $dict['page_path'];
        $identifier->trim();
        return $identifier;
    }

    public function trim()
    {
        $docset_filename = $this->docset_filename;
        $docset_filename = preg_replace('/\\.docset$/', '', $docset_filename);
        $docset_filename = preg_replace('/[0-9]+\.*[0-9]+(\.*[0-9]+)*/', '', $docset_filename); // remove versions
        $docset_filename = trim(str_replace(range(0,9),'',$docset_filename)); // remove all numbers
        $this->docset_filename = $docset_filename;
        
        $page_path = $this->page_path;
        $basename = basename($page_path);
        $page_path = substr($page_path, 0, strlen($page_path)-strlen($basename));
        $basename = str_replace(['-2.html', '-3.html', '-4.html', '-5.html', '-6.html', '-7.html', '-8.html', '-9.html'], '.html', $basename);
        $page_path = preg_replace('/v[0-9]+\.*[0-9]+(\.*[0-9]+)*/', '', $page_path); // remove versions like /v1.1.0/
        $page_path = preg_replace('/[0-9]+\.*[0-9]+(\.*[0-9]+)*/', '', $page_path); // remove versions like /1.1.0/
        $page_path = preg_replace('/[0-9]+_*[0-9]+(_*[0-9]+)*/', '', $page_path); // remove versions that use _ instead of . (SQLAlchemy)
        $page_path = str_replace(range(0,9), '', $page_path); // remove all numbers
        $page_path = str_replace(['/-alpha/', '/-alpha./', '/-alpha-/', '/-beta/', '/-beta./', '/-beta-/', '/-rc/', '/-rc./', '/-rc-/', '/.alpha/', '/.alpha./', '/.alpha-/', '/.beta/', '/.beta./', '/.beta-/', '/.rc/', '/.rc./', '/.rc-/'], '/', $page_path);
        $page_path = remove_prefix("www.", $page_path); // remove "www." for online-cloned docsets
        $page_path = trim(str_replace('//', '/', $page_path));
        $page_path .= $basename;
        $this->page_path = $page_path;
    }

    public function find_in_db()
    {
        $toMatch = ['docset_filename' => $this->docset_filename,
                    'page_path' => $this->page_path,
                   ];
        return Identifier::where($toMatch)->first();
    }

    public function entries()
    {
        return $this->hasMany('App\Entry');
    }
}
