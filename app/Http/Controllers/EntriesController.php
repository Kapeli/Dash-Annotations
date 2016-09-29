<?php namespace App\Http\Controllers;

use Validator, Request, Auth, Illuminate\Support\Facades\Hash;
use App\User;
use App\Team;
use App\Identifier;
use App\Vote;
use App\License;
use App\Entry;
use \Michelf\MarkdownExtra;
use \HTML_Safe;

class EntriesController extends Controller {
    public function list_entries()
    {
        $minimum_public_score = -5;
        $identifier_dict = Request::input('identifier');
        $identifier = Identifier::IdentifierFromDictionary($identifier_dict)->find_in_db();
        if($identifier)
        {
            $public_entries = NULL;
            $own_entries = NULL;
            $team_entries = NULL;

            $user = Auth::user();
            if($user)
            {
                $team_ids = array();
                foreach($user->teams()->get() as $team)
                {
                    $team_ids[] = $team->id;
                }

                $public_entries = $identifier->entries()->where('public', '=', 1)
                                                        ->where('user_id', '!=', $user->id)
                                                        ->where('removed_from_public', '!=', 1)
                                                        ->where('score', '>', $minimum_public_score)->get();
                $own_entries = $identifier->entries()->where('user_id', '=', $user->id)->get();
                if(count($team_ids))
                {
                    $team_entries = $identifier->entries()->whereHas('teams', function($query) use ($user, $team_ids)
                                                          {
                                                              $query->where('user_id', '!=', $user->id)
                                                                    ->whereIn('team_id', $team_ids)
                                                                    ->where('removed_from_team', '=', 0);
                                                          })->get();
                    if($team_entries && $public_entries)
                    {
                        $public_entries = $public_entries->filter(function($public_entry) use ($team_entries)
                        {
                            foreach($team_entries as $team_entry)
                            {
                                if($public_entry->id == $team_entry->id)
                                {
                                    return false;
                                }
                            }
                            return true;
                        })->values();
                    }
                }
            }
            else
            {
                $public_entries = $identifier->entries()->where('public', '=', 1)
                                                        ->where('score', '>', $minimum_public_score)
                                                        ->where('removed_from_public', '!=', 1)
                                                        ->get();
            }

            $response = ["status" => "success"];
            if(count($public_entries))
            {
                $response["public_entries"] = $public_entries;
            }
            if(count($own_entries))
            {
                $response["own_entries"] = $own_entries;
            }
            if(count($team_entries))
            {
                $response["team_entries"] = $team_entries;
            }
            return json_encode($response);
        }
        return json_encode(["status" => "success"]);
    }

    public function save()
    {
        if(Auth::check())
        {
            $title = Request::input('title');
            $body = Request::input('body');
            $public = Request::input('public');
            $type = Request::input('type');
            $teams = Request::input('teams');
            $license = Request::input('license');
            $identifier_dict = Request::input('identifier');
            $anchor = Request::input('anchor');
            $entry_id = Request::input('entry_id');
            $user = Auth::user();
            
            if($title !== '' && $body !== '' && $type !== '' && !empty($identifier_dict) && $anchor !== '')
            {
                $db_license = NULL;
                if($public)
                {
                    if(isset($_ENV['AUTH_LICENSES']) && $_ENV['AUTH_LICENSES'])
                    {
                        if(empty($license))
                        {
                            return json_encode(['status' => 'error', 'message' => 'Only paid users can create public annotations']);
                        }

                        $json_license = json_encode($license);
                        $db_license = License::where('license', '=', $json_license)->first();
                        if($db_license)
                        {
                            if($db_license->banned_from_public)
                            {
                                if(isset($license['is_beta']) && $license['is_beta'])
                                {
                                    return json_encode(['status' => 'error', 'message' => "Beta users can't make public annotations"]);
                                }
                                else if(isset($license['is_promo']) && $license['is_promo'])
                                {
                                    return json_encode(['status' => 'error', 'message' => $license['promo_name']." users can't make public annotations"]);
                                }
                                return json_encode(['status' => 'error', 'message' => 'You are banned from making public annotations']);
                            }
                        }
                        else
                        {
                            if(isset($license['is_beta']) && $license['is_beta'])
                            {
                                // skip check for beta users
                            }
                            else if(isset($license['is_promo']) && $license['is_promo'])
                            {
                                // skip check for promo users
                            }
                            else if(isset($license['is_app_store']) && $license['is_app_store'])
                            {
                                if(!DashLicenseUtil::check_itunes_receipt($license))
                                {
                                    return json_encode(['status' => 'error', 'message' => 'Invalid license. Public annotation not allowed']);
                                }
                            }
                            else
                            {
                                if(!DashLicenseUtil::check_license($license))
                                {
                                    return json_encode(['status' => 'error', 'message' => 'Invalid license. Public annotation not allowed']);
                                }
                            }

                            $db_license = new License;
                            $db_license->license = $json_license;
                            $db_license->save();
                        }
                    }
                }

                $identifier = Identifier::IdentifierFromDictionary($identifier_dict);
                $db_identifier = $identifier->find_in_db();
                if(!$db_identifier)
                {
                    $identifier->save();
                    $db_identifier = $identifier;
                }

                if($public && $db_identifier->banned_from_public)
                {
                    return json_encode(['status' => 'error', 'message' => 'Public annotations are not allowed on this page']);
                }

                $entry = ($entry_id) ? Entry::where('id', '=', $entry_id)->first() : new Entry;
                if($entry_id && (!$entry || $entry->user_id != $user->id))
                {
                    return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
                }
                $entry->title = $title;
                $entry->body = $body;

                try {
                    $body = MarkdownExtra::defaultTransform($body);
                } catch (\RuntimeException $e) {
                    $message = $e->getMessage();
                    $start = strpos($message, 'no lexer for alias \'');
                    if($start !== FALSE)
                    {
                        $start += 20;
                        $end = strpos($message, '\'', $start);
                        if($end !== FALSE)
                        {
                            $lexer = substr($message, $start, $end-$start);
                            return json_encode(['status' => 'error', 'message' => 'Unknown syntax highlighting: '.$lexer]);
                        }
                    }
                    throw $e;
                }
                $html_safe = new HTML_Safe();
                $html_safe->protocolFiltering = 'black';
                $body = $html_safe->parse($body);
                $body = str_replace('#dashInternal', '#', $body);
                $entry->body_rendered = $body;

                $entry->public = $public;
                $entry->type = $type;
                $entry->anchor = $anchor;
                $entry->user_id = $user->id;
                $entry->identifier_id = $db_identifier->id;
                if($db_license)
                {
                    $entry->license_id = $db_license->id;
                }
                if(!$entry_id)
                {
                    $entry->score = 1;
                }
                $entry->save();
                if(!$entry_id)
                {
                    $vote = new Vote;
                    $vote->type = 1;
                    $vote->user_id = $user->id;
                    $vote->entry_id = $entry->id;
                    $vote->save();
                }
                $db_teams = $entry->teams();
                $already_assigned = array();
                foreach($db_teams->get() as $team)
                {
                    if(!in_arrayi($team->name, $teams))
                    {
                        $db_teams->detach($team->id);
                    }
                    else
                    {
                        $already_assigned[] = $team->name;
                    }
                }
                foreach($teams as $team)
                {
                    if(!in_arrayi($team, $already_assigned))
                    {
                        $db_team = Team::where('name', '=', $team)->first();
                        if($db_team && $db_team->users()->where('user_id', '=', $user->id)->first())
                        {
                            $db_team->entries()->attach($entry->id);
                        }
                    }
                }
                return json_encode(['status' => 'success', 'entry' => $entry]);
            }
            return json_encode(['status' => 'error', 'message' => 'Oops. Unknown error']);
        }
        return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
    }

    public function delete()
    {
        if(Auth::check())
        {
            $entry_id = Request::input('entry_id');
            $entry = Entry::where('id', '=', $entry_id)->first();
            $user = Auth::user();
            if($entry && $entry->user_id == $user->id)
            {
                $entry->teams()->detach();
                Vote::where('entry_id', '=', $entry_id)->delete();
                $entry->delete();
                return json_encode(['status' => 'success']);
            }
        }
        return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
    }

    public function remove_from_public()
    {
        if(Auth::check())
        {
            $user = Auth::user();
            if($user->moderator)
            {
                $entry_id = Request::input('entry_id');
                $entry = Entry::where('id', '=', $entry_id)->first();
                if($entry)
                {
                    $entry->removed_from_public = true;
                    $entry->save();
                    return json_encode(['status' => 'success']);
                }
            }
        }
        return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
    }

    public function remove_from_teams()
    {
        if(Auth::check())
        {
            $user = Auth::user();
            $entry_id = Request::input('entry_id');
            $entry = Entry::where('id', '=', $entry_id)->first();
            if($entry)
            {
                $entry_teams = $entry->teams()->get();
                foreach($entry_teams as $team) 
                {
                    $check = $team->users()->where('user_id', '=', $user->id)->first();
                    if($check)
                    {
                        $role = $check->pivot->role;
                        if($role && ($role == 'owner' || $role == 'moderator'))
                        {
                            $team->pivot->removed_from_team = true;
                            $team->pivot->save();
                        }
                    }
                }
                return json_encode(['status' => 'success']);
            }
        }
        return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
    }

    public function vote()
    {
        if(Auth::check())
        {
            $entry_id = Request::input('entry_id');
            $vote_type = Request::input('vote_type');
            if($vote_type > 1 || $vote_type < -1)
            {
                return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
            }
            $entry = Entry::where('id', '=', $entry_id)->first();
            if($entry)
            {
                $entry_teams = $entry->teams()->get();
                $user = Auth::user();
                if(!$entry->public && $entry->user_id != $user->id)
                {
                    $found = false;
                    foreach($entry_teams as $team) 
                    {
                        $check = $team->users()->where('user_id', '=', $user->id)->first();
                        if($check)
                        {
                            $found = true;
                            break;
                        }
                    }
                    if(!$found)
                    {
                        return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
                    }
                }
                $vote = Vote::where('entry_id', '=', $entry_id)->where('user_id', '=', $user->id)->first();
                if($vote)
                {
                    $entry->score -= $vote->type;
                    if($vote_type == 0)
                    {
                        $entry->save();
                        $vote->delete();
                        return json_encode(['status' => 'success']);
                    }
                }
                else if($vote_type == 0)
                {
                    return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
                }
                $vote = ($vote) ? $vote : new Vote;
                $vote->entry_id = $entry_id;
                $vote->user_id = $user->id;
                $vote->type = $vote_type;
                $entry->score += $vote_type;
                $entry->save();
                $vote->save();
                return json_encode(['status' => 'success']);
            }
        }
        return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
    }

    public function get()
    {
        $entry_id = Request::input('entry_id');
        $entry = Entry::where('id', '=', $entry_id)->first();
        if($entry)
        {
            $my_teams = array();
            $global_moderator = false;
            $team_moderator = false;
            $entry_user = $entry->user()->first();
            $user = NULL;
            if(!Auth::check())
            {
                if(!$entry->public)
                {
                    return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
                }
            }
            else
            {
                $entry_teams = $entry->teams()->get();
                $user = Auth::user();
                foreach($entry_teams as $team) 
                {
                    if($entry->user_id == $user->id || !$team->pivot->removed_from_team)
                    {
                        $check = $team->users()->where('user_id', '=', $user->id)->first();
                        if($check)
                        {
                            $role = $check->pivot->role;
                            $my_teams[] = ["name" => $team->name, "role" => $role];
                            if($role && ($role == 'owner' || $role == 'moderator'))
                            {
                                $team_moderator = true;
                            }
                        }
                    }
                }
                if(!$entry->public && !count($my_teams) && $entry->user_id != $user->id)
                {
                    return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
                }
                if($entry->public && $user->moderator)
                {
                    $global_moderator = true;
                }
            }
            $body_rendered = $entry->body_rendered;
            $body = '
            <!DOCTYPE html>
            <html lang="en">
                <head>
                    <title>".$entry->title."</title>
                    <meta charset="utf-8" />
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <link rel="stylesheet" href="http://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap.min.css">
                    <link rel="stylesheet" href="http://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap-theme.min.css">
                    <style>
                        code {
                            color: black;
                            background-color: #f5f5f5;
                            border-radius: 4px;
                            border: 1px solid #ccc;
                            padding: 1px 5px;
                        }
                        pre code {
                            border: none;
                        }
                        pre {
                          overflow: auto;
                          word-wrap: normal;
                          white-space: pre;
                        }

                        code {
                            white-space: nowrap;
                        }

                        pre code {
                            white-space: pre;
                        }
                        
                        /* Pygmentize theme: Friendly */
                        .highlight .hll { background-color: #ffffcc }
                        .highlight .c { color: #60a0b0; font-style: italic } /* Comment */
                        .highlight .err { border: 1px solid #FF0000 } /* Error */
                        .highlight .k { color: #007020; font-weight: bold } /* Keyword */
                        .highlight .o { color: #666666 } /* Operator */
                        .highlight .cm { color: #60a0b0; font-style: italic } /* Comment.Multiline */
                        .highlight .cp { color: #007020 } /* Comment.Preproc */
                        .highlight .c1 { color: #60a0b0; font-style: italic } /* Comment.Single */
                        .highlight .cs { color: #60a0b0; background-color: #fff0f0 } /* Comment.Special */
                        .highlight .gd { color: #A00000 } /* Generic.Deleted */
                        .highlight .ge { font-style: italic } /* Generic.Emph */
                        .highlight .gr { color: #FF0000 } /* Generic.Error */
                        .highlight .gh { color: #000080; font-weight: bold } /* Generic.Heading */
                        .highlight .gi { color: #00A000 } /* Generic.Inserted */
                        .highlight .go { color: #808080 } /* Generic.Output */
                        .highlight .gp { color: #c65d09; font-weight: bold } /* Generic.Prompt */
                        .highlight .gs { font-weight: bold } /* Generic.Strong */
                        .highlight .gu { color: #800080; font-weight: bold } /* Generic.Subheading */
                        .highlight .gt { color: #0040D0 } /* Generic.Traceback */
                        .highlight .kc { color: #007020; font-weight: bold } /* Keyword.Constant */
                        .highlight .kd { color: #007020; font-weight: bold } /* Keyword.Declaration */
                        .highlight .kn { color: #007020; font-weight: bold } /* Keyword.Namespace */
                        .highlight .kp { color: #007020 } /* Keyword.Pseudo */
                        .highlight .kr { color: #007020; font-weight: bold } /* Keyword.Reserved */
                        .highlight .kt { color: #902000 } /* Keyword.Type */
                        .highlight .m { color: #40a070 } /* Literal.Number */
                        .highlight .s { color: #4070a0 } /* Literal.String */
                        .highlight .na { color: #4070a0 } /* Name.Attribute */
                        .highlight .nb { color: #007020 } /* Name.Builtin */
                        .highlight .nc { color: #0e84b5; font-weight: bold } /* Name.Class */
                        .highlight .no { color: #60add5 } /* Name.Constant */
                        .highlight .nd { color: #555555; font-weight: bold } /* Name.Decorator */
                        .highlight .ni { color: #d55537; font-weight: bold } /* Name.Entity */
                        .highlight .ne { color: #007020 } /* Name.Exception */
                        .highlight .nf { color: #06287e } /* Name.Function */
                        .highlight .nl { color: #002070; font-weight: bold } /* Name.Label */
                        .highlight .nn { color: #0e84b5; font-weight: bold } /* Name.Namespace */
                        .highlight .nt { color: #062873; font-weight: bold } /* Name.Tag */
                        .highlight .nv { color: #bb60d5 } /* Name.Variable */
                        .highlight .ow { color: #007020; font-weight: bold } /* Operator.Word */
                        .highlight .w { color: #bbbbbb } /* Text.Whitespace */
                        .highlight .mf { color: #40a070 } /* Literal.Number.Float */
                        .highlight .mh { color: #40a070 } /* Literal.Number.Hex */
                        .highlight .mi { color: #40a070 } /* Literal.Number.Integer */
                        .highlight .mo { color: #40a070 } /* Literal.Number.Oct */
                        .highlight .sb { color: #4070a0 } /* Literal.String.Backtick */
                        .highlight .sc { color: #4070a0 } /* Literal.String.Char */
                        .highlight .sd { color: #4070a0; font-style: italic } /* Literal.String.Doc */
                        .highlight .s2 { color: #4070a0 } /* Literal.String.Double */
                        .highlight .se { color: #4070a0; font-weight: bold } /* Literal.String.Escape */
                        .highlight .sh { color: #4070a0 } /* Literal.String.Heredoc */
                        .highlight .si { color: #70a0d0; font-style: italic } /* Literal.String.Interpol */
                        .highlight .sx { color: #c65d09 } /* Literal.String.Other */
                        .highlight .sr { color: #235388 } /* Literal.String.Regex */
                        .highlight .s1 { color: #4070a0 } /* Literal.String.Single */
                        .highlight .ss { color: #517918 } /* Literal.String.Symbol */
                        .highlight .bp { color: #007020 } /* Name.Builtin.Pseudo */
                        .highlight .vc { color: #bb60d5 } /* Name.Variable.Class */
                        .highlight .vg { color: #bb60d5 } /* Name.Variable.Global */
                        .highlight .vi { color: #bb60d5 } /* Name.Variable.Instance */
                        .highlight .il { color: #40a070 } /* Literal.Number.Integer.Long */


                        html, body {
                            background-color:transparent;
                        }
                        h1 {
                            font-size:24px;
                        }
                        h2 {
                            font-size:22px;
                        }
                        h3 {
                            font-size:20px;
                        }
                        h4 {
                            font-size:18px;
                        }
                        h5 {
                            font-size:16px;
                        }
                        h6 {
                            font-size:14px;
                        }

                        h2.title {
                            padding-bottom:0px;
                            margin-bottom:2px;
                            margin-top:12px;
                        }

                        p.description {
                            padding-bottom:2px;
                            margin-top:3px;
                        }

                        code a, code a:hover, code a:visited {
                            color:inherit;
                        }

                        .vote {
                            float:left;
                            width:48px;
                            text-align: center;
                            height:64px;
                            margin-left:-10px;
                            margin-top:1px;
                        }

                        .score {
                            line-height:12px;
                            font-size:18px;
                            padding-top: 1px;
                            margin-left:2px;
                        }

                        .arrow-up {
                            padding-top:9px;
                            cursor: pointer;
                        }
                        .arrow-down {
                            padding-top:3px;
                            cursor: pointer;
                        }
                        .arrow-up.voted {
                            color:green;
                        }
                        .arrow-down.voted {
                            color:red;
                        }
                        .noselect {
                            -webkit-touch-callout: none;
                            -webkit-user-select: none;
                            user-select:none;
                        }
                        .actions {
                            padding: 1px 4px;
                            font-size: 90%;
                            vertical-align:1px;
                            cursor: pointer;
                            white-space:nowrap;
                            box-shadow:none;
                            font-family: "Helvetica Neue",Helvetica,Arial,sans-serif;
                        }
                        .actions.edit {
                            background-color:#286090;
                        }
                        .actions.delete {
                            background-color:#c9302c;
                        }
                        .dash-internal {
                            color: inherit !important;
                            text-decoration: none !important;
                        }

                        hr {
                            border-top: 1px solid #666666;
                        }

                    </style>
                </head>
                <body>
                    <div class="container-fluid">';

            if($entry->public || count($my_teams))
            {
                $voted_up = "";
                $voted_down = "";
                if($user)
                {
                    $vote = Vote::where('user_id', '=', $user->id)->where('entry_id', '=', $entry->id)->first();
                    if($vote)
                    {
                        $voted_up = ($vote->type == 1) ? "voted" : "";
                        $voted_down = ($vote->type == -1) ? "voted" : "";
                    }
                }
                $score = ($entry->score > 999) ? 999 : $entry->score;
                $score = ($score < -999) ? -999 : $score;
                $body .= '
                    <div class="vote noselect">
                        <a class="dash-internal" href="#dashInternalVoteUp"><div class="arrow-up glyphicon glyphicon-arrow-up '.$voted_up.'"></div></a>
                        <div class="score">'.$score.'</div>
                        <a class="dash-internal" href="#dashInternalVoteDown"><div class="arrow-down glyphicon glyphicon-arrow-down '.$voted_down.'"></div></a>
                    </div>';            
            }
            $body .= '
                    <div><h2 class="title">'.htmlentities($entry->title, ENT_QUOTES).'</h2>
                    <p class="description"><small>';

            $body .= ($entry->public && !($entry->removed_from_public && $global_moderator)) ? "Public annotation " : @"Private annotation ";
            $body .= 'by <u>'.htmlentities($entry_user->username, ENT_QUOTES).'</u>';
            $team_string = "";
            $i = 0;
            foreach($my_teams as $team)
            {
                ++$i;
                if(strlen($team_string))
                {
                    $team_string .= (count($my_teams) == $i) ? " and " : ", ";
                }
                $team_string .= '<u>'.htmlentities($team['name'], ENT_QUOTES).'</u>';
            }
            if(strlen($team_string))
            {
                $body .= ' in '.$team_string.'';
            }
            $body .= ' ';
            if($user && $user->id == $entry->user_id)
            {
                $body .= '&nbsp;<a class="dash-internal" href="#dashInternalEdit"><kbd class="actions edit">Edit</kbd></a>';
                $body .= '&nbsp;<a class="dash-internal" href="#dashInternalDelete"><kbd class="actions delete">Delete</kbd></a>';
            }
            else
            {
                if($global_moderator && $entry->public && !$entry->removed_from_public)
                {
                    $body .= '&nbsp;<a class="dash-internal" href="#dashInternalRemoveFromPublic"><kbd class="actions delete">Remove From Public</kbd></a>';
                }
                if($team_moderator)
                {
                    $body .= '&nbsp;<a class="dash-internal" href="#dashInternalRemoveFromTeams"><kbd class="actions delete">Remove From Team';
                    if(count($my_teams) > 1)
                    {
                        $body .= 's';
                    }
                    $body .= '</kbd></a>';
                }
            }
            $body .= '</small></div>
                    <div id="dash-annotation-body">'.$body_rendered.'</div>
                    </div>
                    <script>'.autolinker_js().'
                    var body = document.getElementById("dash-annotation-body");
                    body.innerHTML = Autolinker.link(body.innerHTML, {newWindow: false, stripPrefix: false, phone: false, twitter: false, hashtag: false});
                    </script>
                </body>
            </html>';
            return json_encode(["status" => "success", "body" => $entry->body, "body_rendered" => $body, "teams" => $my_teams, "global_moderator" => $global_moderator]);
        }
        return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
    }
}

function in_arrayi($needle, $haystack)
{
    return in_array(strtolower($needle), array_map('strtolower', $haystack));
}

function autolinker_js()
{

    // Copy paste from https://github.com/gregjacobs/Autolinker.js/blob/master/dist/Autolinker.min.js, just need to replace \ with \\ for some reason

    return <<<END
    /*!
     * Autolinker.js
     * 0.21.0
     *
     * Copyright(c) 2015 Gregory Jacobs <greg@greg-jacobs.com>
     * MIT
     *
     * https://github.com/gregjacobs/Autolinker.js
     */
    !function(a,b){"function"==typeof define&&define.amd?define([],function(){return a.Autolinker=b()}):"object"==typeof exports?module.exports=b():a.Autolinker=b()}(this,function(){var a=function(b){a.Util.assign(this,b);var c=this.hashtag;if(c!==!1&&"twitter"!==c&&"facebook"!==c&&"instagram"!==c)throw new Error("invalid `hashtag` cfg - see docs");var d=this.truncate=this.truncate||{};"number"==typeof d?this.truncate={length:d,location:"end"}:"object"==typeof d&&(this.truncate.length=d.length||Number.POSITIVE_INFINITY,this.truncate.location=d.location||"end")};return a.prototype={constructor:a,urls:!0,email:!0,twitter:!0,phone:!0,hashtag:!1,newWindow:!0,stripPrefix:!0,truncate:void 0,className:"",htmlParser:void 0,matchParser:void 0,tagBuilder:void 0,link:function(a){if(!a)return"";for(var b=this.getHtmlParser(),c=b.parse(a),d=0,e=[],f=0,g=c.length;g>f;f++){var h=c[f],i=h.getType(),j=h.getText();if("element"===i)"a"===h.getTagName()&&(h.isClosing()?d=Math.max(d-1,0):d++),e.push(j);else if("entity"===i||"comment"===i)e.push(j);else if(0===d){var k=this.linkifyStr(j);e.push(k)}else e.push(j)}return e.join("")},linkifyStr:function(a){return this.getMatchParser().replace(a,this.createMatchReturnVal,this)},createMatchReturnVal:function(b){var c;if(this.replaceFn&&(c=this.replaceFn.call(this,this,b)),"string"==typeof c)return c;if(c===!1)return b.getMatchedText();if(c instanceof a.HtmlTag)return c.toAnchorString();var d=this.getTagBuilder(),e=d.build(b);return e.toAnchorString()},getHtmlParser:function(){var b=this.htmlParser;return b||(b=this.htmlParser=new a.htmlParser.HtmlParser),b},getMatchParser:function(){var b=this.matchParser;return b||(b=this.matchParser=new a.matchParser.MatchParser({urls:this.urls,email:this.email,twitter:this.twitter,phone:this.phone,hashtag:this.hashtag,stripPrefix:this.stripPrefix})),b},getTagBuilder:function(){var b=this.tagBuilder;return b||(b=this.tagBuilder=new a.AnchorTagBuilder({newWindow:this.newWindow,truncate:this.truncate,className:this.className})),b}},a.link=function(b,c){var d=new a(c);return d.link(b)},a.match={},a.htmlParser={},a.matchParser={},a.truncate={},a.Util={abstractMethod:function(){throw"abstract"},trimRegex:/^[\\s\\uFEFF\\xA0]+|[\\s\\uFEFF\\xA0]+$/g,assign:function(a,b){for(var c in b)b.hasOwnProperty(c)&&(a[c]=b[c]);return a},extend:function(b,c){var d=b.prototype,e=function(){};e.prototype=d;var f;f=c.hasOwnProperty("constructor")?c.constructor:function(){d.constructor.apply(this,arguments)};var g=f.prototype=new e;return g.constructor=f,g.superclass=d,delete c.constructor,a.Util.assign(g,c),f},ellipsis:function(a,b,c){return a.length>b&&(c=null==c?"..":c,a=a.substring(0,b-c.length)+c),a},indexOf:function(a,b){if(Array.prototype.indexOf)return a.indexOf(b);for(var c=0,d=a.length;d>c;c++)if(a[c]===b)return c;return-1},splitAndCapture:function(a,b){if(!b.global)throw new Error("`splitRegex` must have the 'g' flag set");for(var c,d=[],e=0;c=b.exec(a);)d.push(a.substring(e,c.index)),d.push(c[0]),e=c.index+c[0].length;return d.push(a.substring(e)),d},trim:function(a){return a.replace(this.trimRegex,"")}},a.HtmlTag=a.Util.extend(Object,{whitespaceRegex:/\\s+/,constructor:function(b){a.Util.assign(this,b),this.innerHtml=this.innerHtml||this.innerHTML},setTagName:function(a){return this.tagName=a,this},getTagName:function(){return this.tagName||""},setAttr:function(a,b){var c=this.getAttrs();return c[a]=b,this},getAttr:function(a){return this.getAttrs()[a]},setAttrs:function(b){var c=this.getAttrs();return a.Util.assign(c,b),this},getAttrs:function(){return this.attrs||(this.attrs={})},setClass:function(a){return this.setAttr("class",a)},addClass:function(b){for(var c,d=this.getClass(),e=this.whitespaceRegex,f=a.Util.indexOf,g=d?d.split(e):[],h=b.split(e);c=h.shift();)-1===f(g,c)&&g.push(c);return this.getAttrs()["class"]=g.join(" "),this},removeClass:function(b){for(var c,d=this.getClass(),e=this.whitespaceRegex,f=a.Util.indexOf,g=d?d.split(e):[],h=b.split(e);g.length&&(c=h.shift());){var i=f(g,c);-1!==i&&g.splice(i,1)}return this.getAttrs()["class"]=g.join(" "),this},getClass:function(){return this.getAttrs()["class"]||""},hasClass:function(a){return-1!==(" "+this.getClass()+" ").indexOf(" "+a+" ")},setInnerHtml:function(a){return this.innerHtml=a,this},getInnerHtml:function(){return this.innerHtml||""},toAnchorString:function(){var a=this.getTagName(),b=this.buildAttrsStr();return b=b?" "+b:"",["<",a,b,">",this.getInnerHtml(),"</",a,">"].join("")},buildAttrsStr:function(){if(!this.attrs)return"";var a=this.getAttrs(),b=[];for(var c in a)a.hasOwnProperty(c)&&b.push(c+'="'+a[c]+'"');return b.join(" ")}}),a.AnchorTagBuilder=a.Util.extend(Object,{constructor:function(b){a.Util.assign(this,b)},build:function(b){return new a.HtmlTag({tagName:"a",attrs:this.createAttrs(b.getType(),b.getAnchorHref()),innerHtml:this.processAnchorText(b.getAnchorText())})},createAttrs:function(a,b){var c={href:b},d=this.createCssClass(a);return d&&(c["class"]=d),this.newWindow&&(c.target="_blank"),c},createCssClass:function(a){var b=this.className;return b?b+" "+b+"-"+a:""},processAnchorText:function(a){return a=this.doTruncate(a)},doTruncate:function(b){var c=this.truncate;if(!c)return b;var d=c.length,e=c.location;return"smart"===e?a.truncate.TruncateSmart(b,d,".."):"middle"===e?a.truncate.TruncateMiddle(b,d,".."):a.truncate.TruncateEnd(b,d,"..")}}),a.htmlParser.HtmlParser=a.Util.extend(Object,{htmlRegex:function(){var a=/!--([\\s\\S]+?)--/,b=/[0-9a-zA-Z][0-9a-zA-Z:]*/,c=/[^\\s\\0"'>\\/=\\x01-\\x1F\\x7F]+/,d=/(?:"[^"]*?"|'[^']*?'|[^'"=<>`\\s]+)/,e=c.source+"(?:\\\\s*=\\\\s*"+d.source+")?";return new RegExp(["(?:","<(!DOCTYPE)","(?:","\\\\s+","(?:",e,"|",d.source+")",")*",">",")","|","(?:","<(/)?","(?:",a.source,"|","(?:","("+b.source+")","(?:","\\\\s+",e,")*","\\\\s*/?",")",")",">",")"].join(""),"gi")}(),htmlCharacterEntitiesRegex:/(&nbsp;|&#160;|&lt;|&#60;|&gt;|&#62;|&quot;|&#34;|&#39;)/gi,parse:function(a){for(var b,c,d=this.htmlRegex,e=0,f=[];null!==(b=d.exec(a));){var g=b[0],h=b[3],i=b[1]||b[4],j=!!b[2],k=a.substring(e,b.index);k&&(c=this.parseTextAndEntityNodes(k),f.push.apply(f,c)),f.push(h?this.createCommentNode(g,h):this.createElementNode(g,i,j)),e=b.index+g.length}if(e<a.length){var l=a.substring(e);l&&(c=this.parseTextAndEntityNodes(l),f.push.apply(f,c))}return f},parseTextAndEntityNodes:function(b){for(var c=[],d=a.Util.splitAndCapture(b,this.htmlCharacterEntitiesRegex),e=0,f=d.length;f>e;e+=2){var g=d[e],h=d[e+1];g&&c.push(this.createTextNode(g)),h&&c.push(this.createEntityNode(h))}return c},createCommentNode:function(b,c){return new a.htmlParser.CommentNode({text:b,comment:a.Util.trim(c)})},createElementNode:function(b,c,d){return new a.htmlParser.ElementNode({text:b,tagName:c.toLowerCase(),closing:d})},createEntityNode:function(b){return new a.htmlParser.EntityNode({text:b})},createTextNode:function(b){return new a.htmlParser.TextNode({text:b})}}),a.htmlParser.HtmlNode=a.Util.extend(Object,{text:"",constructor:function(b){a.Util.assign(this,b)},getType:a.Util.abstractMethod,getText:function(){return this.text}}),a.htmlParser.CommentNode=a.Util.extend(a.htmlParser.HtmlNode,{comment:"",getType:function(){return"comment"},getComment:function(){return this.comment}}),a.htmlParser.ElementNode=a.Util.extend(a.htmlParser.HtmlNode,{tagName:"",closing:!1,getType:function(){return"element"},getTagName:function(){return this.tagName},isClosing:function(){return this.closing}}),a.htmlParser.EntityNode=a.Util.extend(a.htmlParser.HtmlNode,{getType:function(){return"entity"}}),a.htmlParser.TextNode=a.Util.extend(a.htmlParser.HtmlNode,{getType:function(){return"text"}}),a.matchParser.MatchParser=a.Util.extend(Object,{urls:!0,email:!0,twitter:!0,phone:!0,hashtag:!1,stripPrefix:!0,matcherRegex:function(){var a=/(^|[^\\w])@(\\w{1,15})/,b=/(^|[^\\w])#(\\w{1,139})/,c=/(?:[\\-;:&=\\+\\$,\\w\\.]+@)/,d=/(?:(\\+)?\\d{1,3}[-\\040.])?\\(?\\d{3}\\)?[-\\040.]?\\d{3}[-\\040.]\\d{4}/,e=/(?:[A-Za-z][-.+A-Za-z0-9]*:(?![A-Za-z][-.+A-Za-z0-9]*:\\/\\/)(?!\\d+\\/?)(?:\\/\\/)?)/,f=/(?:www\\.)/,g=/[A-Za-z0-9\\.\\-]*[A-Za-z0-9\\-]/,h=/\\.(?:international|construction|contractors|enterprises|photography|productions|foundation|immobilien|industries|management|properties|technology|christmas|community|directory|education|equipment|institute|marketing|solutions|vacations|bargains|boutique|builders|catering|cleaning|clothing|computer|democrat|diamonds|graphics|holdings|lighting|partners|plumbing|supplies|training|ventures|academy|careers|company|cruises|domains|exposed|flights|florist|gallery|guitars|holiday|kitchen|neustar|okinawa|recipes|rentals|reviews|shiksha|singles|support|systems|agency|berlin|camera|center|coffee|condos|dating|estate|events|expert|futbol|kaufen|luxury|maison|monash|museum|nagoya|photos|repair|report|social|supply|tattoo|tienda|travel|viajes|villas|vision|voting|voyage|actor|build|cards|cheap|codes|dance|email|glass|house|mango|ninja|parts|photo|press|shoes|solar|today|tokyo|tools|watch|works|aero|arpa|asia|best|bike|blue|buzz|camp|club|cool|coop|farm|fish|gift|guru|info|jobs|kiwi|kred|land|limo|link|menu|mobi|moda|name|pics|pink|post|qpon|rich|ruhr|sexy|tips|vote|voto|wang|wien|wiki|zone|bar|bid|biz|cab|cat|ceo|com|edu|gov|int|kim|mil|net|onl|org|pro|pub|red|tel|uno|wed|xxx|xyz|ac|ad|ae|af|ag|ai|al|am|an|ao|aq|ar|as|at|au|aw|ax|az|ba|bb|bd|be|bf|bg|bh|bi|bj|bm|bn|bo|br|bs|bt|bv|bw|by|bz|ca|cc|cd|cf|cg|ch|ci|ck|cl|cm|cn|co|cr|cu|cv|cw|cx|cy|cz|de|dj|dk|dm|do|dz|ec|ee|eg|er|es|et|eu|fi|fj|fk|fm|fo|fr|ga|gb|gd|ge|gf|gg|gh|gi|gl|gm|gn|gp|gq|gr|gs|gt|gu|gw|gy|hk|hm|hn|hr|ht|hu|id|ie|il|im|in|io|iq|ir|is|it|je|jm|jo|jp|ke|kg|kh|ki|km|kn|kp|kr|kw|ky|kz|la|lb|lc|li|lk|lr|ls|lt|lu|lv|ly|ma|mc|md|me|mg|mh|mk|ml|mm|mn|mo|mp|mq|mr|ms|mt|mu|mv|mw|mx|my|mz|na|nc|ne|nf|ng|ni|nl|no|np|nr|nu|nz|om|pa|pe|pf|pg|ph|pk|pl|pm|pn|pr|ps|pt|pw|py|qa|re|ro|rs|ru|rw|sa|sb|sc|sd|se|sg|sh|si|sj|sk|sl|sm|sn|so|sr|st|su|sv|sx|sy|sz|tc|td|tf|tg|th|tj|tk|tl|tm|tn|to|tp|tr|tt|tv|tw|tz|ua|ug|uk|us|uy|uz|va|vc|ve|vg|vi|vn|vu|wf|ws|ye|yt|za|zm|zw)\\b/,i=/[\\-A-Za-z0-9+&@#\\/%=~_()|'$*\\[\\]?!:,.;]*[\\-A-Za-z0-9+&@#\\/%=~_()|'$*\\[\\]]/;return new RegExp(["(",a.source,")","|","(",c.source,g.source,h.source,")","|","(","(?:","(",e.source,g.source,")","|","(?:","(.?//)?",f.source,g.source,")","|","(?:","(.?//)?",g.source,h.source,")",")","(?:"+i.source+")?",")","|","(",d.source,")","|","(",b.source,")"].join(""),"gi")}(),charBeforeProtocolRelMatchRegex:/^(.)?\\/\\//,constructor:function(b){a.Util.assign(this,b),this.matchValidator=new a.MatchValidator},replace:function(a,b,c){var d=this;return a.replace(this.matcherRegex,function(a,e,f,g,h,i,j,k,l,m,n,o,p,q){var r=d.processCandidateMatch(a,e,f,g,h,i,j,k,l,m,n,o,p,q);if(r){var s=b.call(c,r.match);return r.prefixStr+s+r.suffixStr}return a})},processCandidateMatch:function(b,c,d,e,f,g,h,i,j,k,l,m,n,o){var p,q=i||j,r="",s="";if(g&&!this.urls||f&&!this.email||k&&!this.phone||c&&!this.twitter||m&&!this.hashtag||!this.matchValidator.isValidMatch(g,h,q))return null;if(this.matchHasUnbalancedClosingParen(b))b=b.substr(0,b.length-1),s=")";else{var t=this.matchHasInvalidCharAfterTld(g,h);t>-1&&(s=b.substr(t),b=b.substr(0,t))}if(f)p=new a.match.Email({matchedText:b,email:f});else if(c)d&&(r=d,b=b.slice(1)),p=new a.match.Twitter({matchedText:b,twitterHandle:e});else if(k){var u=b.replace(/\\D/g,"");p=new a.match.Phone({matchedText:b,number:u,plusSign:!!l})}else if(m)n&&(r=n,b=b.slice(1)),p=new a.match.Hashtag({matchedText:b,serviceName:this.hashtag,hashtag:o});else{if(q){var v=q.match(this.charBeforeProtocolRelMatchRegex)[1]||"";v&&(r=v,b=b.slice(1))}p=new a.match.Url({matchedText:b,url:b,protocolUrlMatch:!!h,protocolRelativeMatch:!!q,stripPrefix:this.stripPrefix})}return{prefixStr:r,suffixStr:s,match:p}},matchHasUnbalancedClosingParen:function(a){var b=a.charAt(a.length-1);if(")"===b){var c=a.match(/\\(/g),d=a.match(/\\)/g),e=c&&c.length||0,f=d&&d.length||0;if(f>e)return!0}return!1},matchHasInvalidCharAfterTld:function(a,b){if(!a)return-1;var c=0;b&&(c=a.indexOf(":"),a=a.slice(c));var d=/^((.?\\/\\/)?[A-Za-z0-9\\.\\-]*[A-Za-z0-9\\-]\\.[A-Za-z]+)/,e=d.exec(a);return null===e?-1:(c+=e[1].length,a=a.slice(e[1].length),/^[^.A-Za-z:\\/?#]/.test(a)?c:-1)}}),a.MatchValidator=a.Util.extend(Object,{invalidProtocolRelMatchRegex:/^[\\w]\\/\\//,hasFullProtocolRegex:/^[A-Za-z][-.+A-Za-z0-9]*:\\/\\//,uriSchemeRegex:/^[A-Za-z][-.+A-Za-z0-9]*:/,hasWordCharAfterProtocolRegex:/:[^\\s]*?[A-Za-z]/,isValidMatch:function(a,b,c){return b&&!this.isValidUriScheme(b)||this.urlMatchDoesNotHaveProtocolOrDot(a,b)||this.urlMatchDoesNotHaveAtLeastOneWordChar(a,b)||this.isInvalidProtocolRelativeMatch(c)?!1:!0},isValidUriScheme:function(a){var b=a.match(this.uriSchemeRegex)[0].toLowerCase();return"javascript:"!==b&&"vbscript:"!==b},urlMatchDoesNotHaveProtocolOrDot:function(a,b){return!(!a||b&&this.hasFullProtocolRegex.test(b)||-1!==a.indexOf("."))},urlMatchDoesNotHaveAtLeastOneWordChar:function(a,b){return a&&b?!this.hasWordCharAfterProtocolRegex.test(a):!1},isInvalidProtocolRelativeMatch:function(a){return!!a&&this.invalidProtocolRelMatchRegex.test(a)}}),a.match.Match=a.Util.extend(Object,{constructor:function(b){a.Util.assign(this,b)},getType:a.Util.abstractMethod,getMatchedText:function(){return this.matchedText},getAnchorHref:a.Util.abstractMethod,getAnchorText:a.Util.abstractMethod}),a.match.Email=a.Util.extend(a.match.Match,{getType:function(){return"email"},getEmail:function(){return this.email},getAnchorHref:function(){return"mailto:"+this.email},getAnchorText:function(){return this.email}}),a.match.Hashtag=a.Util.extend(a.match.Match,{getType:function(){return"hashtag"},getHashtag:function(){return this.hashtag},getAnchorHref:function(){var a=this.serviceName,b=this.hashtag;switch(a){case"twitter":return"https://twitter.com/hashtag/"+b;case"facebook":return"https://www.facebook.com/hashtag/"+b;case"instagram":return"https://instagram.com/explore/tags/"+b;default:throw new Error("Unknown service name to point hashtag to: ",a)}},getAnchorText:function(){return"#"+this.hashtag}}),a.match.Phone=a.Util.extend(a.match.Match,{getType:function(){return"phone"},getNumber:function(){return this.number},getAnchorHref:function(){return"tel:"+(this.plusSign?"+":"")+this.number},getAnchorText:function(){return this.matchedText}}),a.match.Twitter=a.Util.extend(a.match.Match,{getType:function(){return"twitter"},getTwitterHandle:function(){return this.twitterHandle},getAnchorHref:function(){return"https://twitter.com/"+this.twitterHandle},getAnchorText:function(){return"@"+this.twitterHandle}}),a.match.Url=a.Util.extend(a.match.Match,{urlPrefixRegex:/^(https?:\\/\\/)?(www\\.)?/i,protocolRelativeRegex:/^\\/\\//,protocolPrepended:!1,getType:function(){return"url"},getUrl:function(){var a=this.url;return this.protocolRelativeMatch||this.protocolUrlMatch||this.protocolPrepended||(a=this.url="http://"+a,this.protocolPrepended=!0),a},getAnchorHref:function(){var a=this.getUrl();return a.replace(/&amp;/g,"&")},getAnchorText:function(){var a=this.getMatchedText();return this.protocolRelativeMatch&&(a=this.stripProtocolRelativePrefix(a)),this.stripPrefix&&(a=this.stripUrlPrefix(a)),a=this.removeTrailingSlash(a)},stripUrlPrefix:function(a){return a.replace(this.urlPrefixRegex,"")},stripProtocolRelativePrefix:function(a){return a.replace(this.protocolRelativeRegex,"")},removeTrailingSlash:function(a){return"/"===a.charAt(a.length-1)&&(a=a.slice(0,-1)),a}}),a.truncate.TruncateEnd=function(b,c,d){return a.Util.ellipsis(b,c,d)},a.truncate.TruncateMiddle=function(a,b,c){if(a.length<=b)return a;var d=b-c.length,e="";return d>0&&(e=a.substr(-1*Math.floor(d/2))),(a.substr(0,Math.ceil(d/2))+c+e).substr(0,b)},a.truncate.TruncateSmart=function(a,b,c){var d=function(a){var b={},c=a,d=c.match(/^([a-z]+):\\/\\//i);return d&&(b.scheme=d[1],c=c.substr(d[0].length)),d=c.match(/^(.*?)(?=(\\?|#|\\/|$))/i),d&&(b.host=d[1],c=c.substr(d[0].length)),d=c.match(/^\\/(.*?)(?=(\\?|#|$))/i),d&&(b.path=d[1],c=c.substr(d[0].length)),d=c.match(/^\\?(.*?)(?=(#|$))/i),d&&(b.query=d[1],c=c.substr(d[0].length)),d=c.match(/^#(.*?)$/i),d&&(b.fragment=d[1]),b},e=function(a){var b="";return a.scheme&&a.host&&(b+=a.scheme+"://"),a.host&&(b+=a.host),a.path&&(b+="/"+a.path),a.query&&(b+="?"+a.query),a.fragment&&(b+="#"+a.fragment),b},f=function(a,b){var d=b/2,e=Math.ceil(d),f=-1*Math.floor(d),g="";return 0>f&&(g=a.substr(f)),a.substr(0,e)+c+g};if(a.length<=b)return a;var g=b-c.length,h=d(a);if(h.query){var i=h.query.match(/^(.*?)(?=(\\?|\\#))(.*?)$/i);i&&(h.query=h.query.substr(0,i[1].length),a=e(h))}if(a.length<=b)return a;if(h.host&&(h.host=h.host.replace(/^www\\./,""),a=e(h)),a.length<=b)return a;var j="";if(h.host&&(j+=h.host),j.length>=g)return h.host.length==b?(h.host.substr(0,b-c.length)+c).substr(0,b):f(j,g).substr(0,b);var k="";if(h.path&&(k+="/"+h.path),h.query&&(k+="?"+h.query),k){if((j+k).length>=g){if((j+k).length==b)return(j+k).substr(0,b);var l=g-j.length;return(j+f(k,l)).substr(0,b)}j+=k}if(h.fragment){var m="#"+h.fragment;if((j+m).length>=g){if((j+m).length==b)return(j+m).substr(0,b);var n=g-j.length;return(j+f(m,n)).substr(0,b)}j+=m}if(h.scheme&&h.host){var o=h.scheme+"://";if((j+o).length<g)return(o+j).substr(0,b)}if(j.length<=b)return j;var p="";return g>0&&(p=j.substr(-1*Math.floor(g/2))),(j.substr(0,Math.ceil(g/2))+c+p).substr(0,b)},a});
END;
}