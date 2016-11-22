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
                    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap.min.css">
                    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap-theme.min.css">
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

                        img {
                            max-width: 100%;
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
     * 1.3.4
     *
     * Copyright(c) 2015 Gregory Jacobs <greg@greg-jacobs.com>
     * MIT
     *
     * https://github.com/gregjacobs/Autolinker.js
     */

    !function(t,e){"function"==typeof define&&define.amd?define([],e):"object"==typeof exports?module.exports=e():t.Autolinker=e()}(this,function(){var t=function(e){e=e||{},this.version=t.version,this.urls=this.normalizeUrlsCfg(e.urls),this.email="boolean"!=typeof e.email||e.email,this.phone="boolean"!=typeof e.phone||e.phone,this.hashtag=e.hashtag||!1,this.mention=e.mention||!1,this.newWindow="boolean"!=typeof e.newWindow||e.newWindow,this.stripPrefix=this.normalizeStripPrefixCfg(e.stripPrefix),this.stripTrailingSlash="boolean"!=typeof e.stripTrailingSlash||e.stripTrailingSlash;var r=this.mention;if(r!==!1&&"twitter"!==r&&"instagram"!==r)throw new Error("invalid `mention` cfg - see docs");var a=this.hashtag;if(a!==!1&&"twitter"!==a&&"facebook"!==a&&"instagram"!==a)throw new Error("invalid `hashtag` cfg - see docs");this.truncate=this.normalizeTruncateCfg(e.truncate),this.className=e.className||"",this.replaceFn=e.replaceFn||null,this.context=e.context||this,this.htmlParser=null,this.matchers=null,this.tagBuilder=null};return t.link=function(e,r){var a=new t(r);return a.link(e)},t.parse=function(e,r){var a=new t(r);return a.parse(e)},t.version="1.3.4",t.prototype={constructor:t,normalizeUrlsCfg:function(t){return null==t&&(t=!0),"boolean"==typeof t?{schemeMatches:t,wwwMatches:t,tldMatches:t}:{schemeMatches:"boolean"!=typeof t.schemeMatches||t.schemeMatches,wwwMatches:"boolean"!=typeof t.wwwMatches||t.wwwMatches,tldMatches:"boolean"!=typeof t.tldMatches||t.tldMatches}},normalizeStripPrefixCfg:function(t){return null==t&&(t=!0),"boolean"==typeof t?{scheme:t,www:t}:{scheme:"boolean"!=typeof t.scheme||t.scheme,www:"boolean"!=typeof t.www||t.www}},normalizeTruncateCfg:function(e){return"number"==typeof e?{length:e,location:"end"}:t.Util.defaults(e||{},{length:Number.POSITIVE_INFINITY,location:"end"})},parse:function(t){for(var e=this.getHtmlParser(),r=e.parse(t),a=0,n=[],i=0,s=r.length;i<s;i++){var o=r[i],c=o.getType();if("element"===c&&"a"===o.getTagName())o.isClosing()?a=Math.max(a-1,0):a++;else if("text"===c&&0===a){var h=this.parseText(o.getText(),o.getOffset());n.push.apply(n,h)}}return n=this.compactMatches(n),n=this.removeUnwantedMatches(n)},compactMatches:function(t){t.sort(function(t,e){return t.getOffset()-e.getOffset()});for(var e=0;e<t.length-1;e++)for(var r=t[e],a=r.getOffset()+r.getMatchedText().length;e+1<t.length&&t[e+1].getOffset()<=a;)t.splice(e+1,1);return t},removeUnwantedMatches:function(e){var r=t.Util.remove;return this.hashtag||r(e,function(t){return"hashtag"===t.getType()}),this.email||r(e,function(t){return"email"===t.getType()}),this.phone||r(e,function(t){return"phone"===t.getType()}),this.mention||r(e,function(t){return"mention"===t.getType()}),this.urls.schemeMatches||r(e,function(t){return"url"===t.getType()&&"scheme"===t.getUrlMatchType()}),this.urls.wwwMatches||r(e,function(t){return"url"===t.getType()&&"www"===t.getUrlMatchType()}),this.urls.tldMatches||r(e,function(t){return"url"===t.getType()&&"tld"===t.getUrlMatchType()}),e},parseText:function(t,e){e=e||0;for(var r=this.getMatchers(),a=[],n=0,i=r.length;n<i;n++){for(var s=r[n].parseMatches(t),o=0,c=s.length;o<c;o++)s[o].setOffset(e+s[o].getOffset());a.push.apply(a,s)}return a},link:function(t){if(!t)return"";for(var e=this.parse(t),r=[],a=0,n=0,i=e.length;n<i;n++){var s=e[n];r.push(t.substring(a,s.getOffset())),r.push(this.createMatchReturnVal(s)),a=s.getOffset()+s.getMatchedText().length}return r.push(t.substring(a)),r.join("")},createMatchReturnVal:function(e){var r;if(this.replaceFn&&(r=this.replaceFn.call(this.context,e)),"string"==typeof r)return r;if(r===!1)return e.getMatchedText();if(r instanceof t.HtmlTag)return r.toAnchorString();var a=e.buildTag();return a.toAnchorString()},getHtmlParser:function(){var e=this.htmlParser;return e||(e=this.htmlParser=new t.htmlParser.HtmlParser),e},getMatchers:function(){if(this.matchers)return this.matchers;var e=t.matcher,r=this.getTagBuilder(),a=[new e.Hashtag({tagBuilder:r,serviceName:this.hashtag}),new e.Email({tagBuilder:r}),new e.Phone({tagBuilder:r}),new e.Mention({tagBuilder:r,serviceName:this.mention}),new e.Url({tagBuilder:r,stripPrefix:this.stripPrefix,stripTrailingSlash:this.stripTrailingSlash})];return this.matchers=a},getTagBuilder:function(){var e=this.tagBuilder;return e||(e=this.tagBuilder=new t.AnchorTagBuilder({newWindow:this.newWindow,truncate:this.truncate,className:this.className})),e}},t.match={},t.matcher={},t.htmlParser={},t.truncate={},t.Util={abstractMethod:function(){throw"abstract"},trimRegex:/^[\\s\\uFEFF\\xA0]+|[\\s\\uFEFF\\xA0]+$/g,assign:function(t,e){for(var r in e)e.hasOwnProperty(r)&&(t[r]=e[r]);return t},defaults:function(t,e){for(var r in e)e.hasOwnProperty(r)&&void 0===t[r]&&(t[r]=e[r]);return t},extend:function(e,r){var a=e.prototype,n=function(){};n.prototype=a;var i;i=r.hasOwnProperty("constructor")?r.constructor:function(){a.constructor.apply(this,arguments)};var s=i.prototype=new n;return s.constructor=i,s.superclass=a,delete r.constructor,t.Util.assign(s,r),i},ellipsis:function(t,e,r){return t.length>e&&(r=null==r?"..":r,t=t.substring(0,e-r.length)+r),t},indexOf:function(t,e){if(Array.prototype.indexOf)return t.indexOf(e);for(var r=0,a=t.length;r<a;r++)if(t[r]===e)return r;return-1},remove:function(t,e){for(var r=t.length-1;r>=0;r--)e(t[r])===!0&&t.splice(r,1)},splitAndCapture:function(t,e){for(var r,a=[],n=0;r=e.exec(t);)a.push(t.substring(n,r.index)),a.push(r[0]),n=r.index+r[0].length;return a.push(t.substring(n)),a},trim:function(t){return t.replace(this.trimRegex,"")}},t.HtmlTag=t.Util.extend(Object,{whitespaceRegex:/\\s+/,constructor:function(e){t.Util.assign(this,e),this.innerHtml=this.innerHtml||this.innerHTML},setTagName:function(t){return this.tagName=t,this},getTagName:function(){return this.tagName||""},setAttr:function(t,e){var r=this.getAttrs();return r[t]=e,this},getAttr:function(t){return this.getAttrs()[t]},setAttrs:function(e){var r=this.getAttrs();return t.Util.assign(r,e),this},getAttrs:function(){return this.attrs||(this.attrs={})},setClass:function(t){return this.setAttr("class",t)},addClass:function(e){for(var r,a=this.getClass(),n=this.whitespaceRegex,i=t.Util.indexOf,s=a?a.split(n):[],o=e.split(n);r=o.shift();)i(s,r)===-1&&s.push(r);return this.getAttrs()["class"]=s.join(" "),this},removeClass:function(e){for(var r,a=this.getClass(),n=this.whitespaceRegex,i=t.Util.indexOf,s=a?a.split(n):[],o=e.split(n);s.length&&(r=o.shift());){var c=i(s,r);c!==-1&&s.splice(c,1)}return this.getAttrs()["class"]=s.join(" "),this},getClass:function(){return this.getAttrs()["class"]||""},hasClass:function(t){return(" "+this.getClass()+" ").indexOf(" "+t+" ")!==-1},setInnerHtml:function(t){return this.innerHtml=t,this},getInnerHtml:function(){return this.innerHtml||""},toAnchorString:function(){var t=this.getTagName(),e=this.buildAttrsStr();return e=e?" "+e:"",["<",t,e,">",this.getInnerHtml(),"</",t,">"].join("")},buildAttrsStr:function(){if(!this.attrs)return"";var t=this.getAttrs(),e=[];for(var r in t)t.hasOwnProperty(r)&&e.push(r+'="'+t[r]+'"');return e.join(" ")}}),t.RegexLib=function(){var t="A-Za-z\\\\xAA\\\\xB5\\\\xBA\\\\xC0-\\\\xD6\\\\xD8-\\\\xF6\\\\xF8-ˁˆ-ˑˠ-ˤˬˮͰ-ʹͶͷͺ-ͽͿΆΈ-ΊΌΎ-ΡΣ-ϵϷ-ҁҊ-ԯԱ-Ֆՙա-ևא-תװ-ײؠ-يٮٯٱ-ۓەۥۦۮۯۺ-ۼۿܐܒ-ܯݍ-ޥޱߊ-ߪߴߵߺࠀ-ࠕࠚࠤࠨࡀ-ࡘࢠ-ࢴऄ-हऽॐक़-ॡॱ-ঀঅ-ঌএঐও-নপ-রলশ-হঽৎড়ঢ়য়-ৡৰৱਅ-ਊਏਐਓ-ਨਪ-ਰਲਲ਼ਵਸ਼ਸਹਖ਼-ੜਫ਼ੲ-ੴઅ-ઍએ-ઑઓ-નપ-રલળવ-હઽૐૠૡૹଅ-ଌଏଐଓ-ନପ-ରଲଳଵ-ହଽଡ଼ଢ଼ୟ-ୡୱஃஅ-ஊஎ-ஐஒ-கஙசஜஞடணதந-பம-ஹௐఅ-ఌఎ-ఐఒ-నప-హఽౘ-ౚౠౡಅ-ಌಎ-ಐಒ-ನಪ-ಳವ-ಹಽೞೠೡೱೲഅ-ഌഎ-ഐഒ-ഺഽൎൟ-ൡൺ-ൿඅ-ඖක-නඳ-රලව-ෆก-ะาำเ-ๆກຂຄງຈຊຍດ-ທນ-ຟມ-ຣລວສຫອ-ະາຳຽເ-ໄໆໜ-ໟༀཀ-ཇཉ-ཬྈ-ྌက-ဪဿၐ-ၕၚ-ၝၡၥၦၮ-ၰၵ-ႁႎႠ-ჅჇჍა-ჺჼ-ቈቊ-ቍቐ-ቖቘቚ-ቝበ-ኈኊ-ኍነ-ኰኲ-ኵኸ-ኾዀዂ-ዅወ-ዖዘ-ጐጒ-ጕጘ-ፚᎀ-ᎏᎠ-Ᏽᏸ-ᏽᐁ-ᙬᙯ-ᙿᚁ-ᚚᚠ-ᛪᛱ-ᛸᜀ-ᜌᜎ-ᜑᜠ-ᜱᝀ-ᝑᝠ-ᝬᝮ-ᝰក-ឳៗៜᠠ-ᡷᢀ-ᢨᢪᢰ-ᣵᤀ-ᤞᥐ-ᥭᥰ-ᥴᦀ-ᦫᦰ-ᧉᨀ-ᨖᨠ-ᩔᪧᬅ-ᬳᭅ-ᭋᮃ-ᮠᮮᮯᮺ-ᯥᰀ-ᰣᱍ-ᱏᱚ-ᱽᳩ-ᳬᳮ-ᳱᳵᳶᴀ-ᶿḀ-ἕἘ-Ἕἠ-ὅὈ-Ὅὐ-ὗὙὛὝὟ-ώᾀ-ᾴᾶ-ᾼιῂ-ῄῆ-ῌῐ-ΐῖ-Ίῠ-Ῥῲ-ῴῶ-ῼⁱⁿₐ-ₜℂℇℊ-ℓℕℙ-ℝℤΩℨK-ℭℯ-ℹℼ-ℿⅅ-ⅉⅎↃↄⰀ-Ⱞⰰ-ⱞⱠ-ⳤⳫ-ⳮⳲⳳⴀ-ⴥⴧⴭⴰ-ⵧⵯⶀ-ⶖⶠ-ⶦⶨ-ⶮⶰ-ⶶⶸ-ⶾⷀ-ⷆⷈ-ⷎⷐ-ⷖⷘ-ⷞⸯ々〆〱-〵〻〼ぁ-ゖゝ-ゟァ-ヺー-ヿㄅ-ㄭㄱ-ㆎㆠ-ㆺㇰ-ㇿ㐀-䶵一-鿕ꀀ-ꒌꓐ-ꓽꔀ-ꘌꘐ-ꘟꘪꘫꙀ-ꙮꙿ-ꚝꚠ-ꛥꜗ-ꜟꜢ-ꞈꞋ-ꞭꞰ-ꞷꟷ-ꠁꠃ-ꠅꠇ-ꠊꠌ-ꠢꡀ-ꡳꢂ-ꢳꣲ-ꣷꣻꣽꤊ-ꤥꤰ-ꥆꥠ-ꥼꦄ-ꦲꧏꧠ-ꧤꧦ-ꧯꧺ-ꧾꨀ-ꨨꩀ-ꩂꩄ-ꩋꩠ-ꩶꩺꩾ-ꪯꪱꪵꪶꪹ-ꪽꫀꫂꫛ-ꫝꫠ-ꫪꫲ-ꫴꬁ-ꬆꬉ-ꬎꬑ-ꬖꬠ-ꬦꬨ-ꬮꬰ-ꭚꭜ-ꭥꭰ-ꯢ가-힣ힰ-ퟆퟋ-ퟻ豈-舘並-龎ﬀ-ﬆﬓ-ﬗיִײַ-ﬨשׁ-זּטּ-לּמּנּסּףּפּצּ-ﮱﯓ-ﴽﵐ-ﶏﶒ-ﷇﷰ-ﷻﹰ-ﹴﹶ-ﻼＡ-Ｚａ-ｚｦ-ﾾￂ-ￇￊ-ￏￒ-ￗￚ-ￜ",e="0-9٠-٩۰-۹߀-߉०-९০-৯੦-੯૦-૯୦-୯௦-௯౦-౯೦-೯൦-൯෦-෯๐-๙໐-໙༠-༩၀-၉႐-႙០-៩᠐-᠙᥆-᥏᧐-᧙᪀-᪉᪐-᪙᭐-᭙᮰-᮹᱀-᱉᱐-᱙꘠-꘩꣐-꣙꤀-꤉꧐-꧙꧰-꧹꩐-꩙꯰-꯹０-９",r=t+e,a=new RegExp("["+r+".\\\\-]*["+r+"\\\\-]"),n=/(?:travelersinsurance|sandvikcoromant|kerryproperties|cancerresearch|weatherchannel|kerrylogistics|spreadbetting|international|wolterskluwer|lifeinsurance|construction|pamperedchef|scholarships|versicherung|bridgestone|creditunion|kerryhotels|investments|productions|blackfriday|enterprises|lamborghini|photography|motorcycles|williamhill|playstation|contractors|barclaycard|accountants|redumbrella|engineering|management|telefonica|protection|consulting|tatamotors|creditcard|vlaanderen|schaeffler|associates|properties|foundation|republican|bnpparibas|boehringer|eurovision|extraspace|industries|immobilien|university|technology|volkswagen|healthcare|restaurant|cuisinella|vistaprint|apartments|accountant|travelers|homedepot|institute|vacations|furniture|fresenius|insurance|christmas|bloomberg|solutions|barcelona|firestone|financial|kuokgroup|fairwinds|community|passagens|goldpoint|equipment|lifestyle|yodobashi|aquarelle|marketing|analytics|education|amsterdam|statefarm|melbourne|allfinanz|directory|microsoft|stockholm|montblanc|accenture|lancaster|landrover|everbank|istanbul|graphics|grainger|ipiranga|softbank|attorney|pharmacy|saarland|catering|airforce|yokohama|mortgage|frontier|mutuelle|stcgroup|memorial|pictures|football|symantec|cipriani|ventures|telecity|cityeats|verisign|flsmidth|boutique|cleaning|firmdale|clinique|clothing|redstone|infiniti|deloitte|feedback|services|broadway|plumbing|commbank|training|barclays|exchange|computer|brussels|software|delivery|barefoot|builders|business|bargains|engineer|holdings|download|security|helsinki|lighting|movistar|discount|hdfcbank|supplies|marriott|property|diamonds|capetown|partners|democrat|jpmorgan|bradesco|budapest|rexroth|zuerich|shriram|academy|science|support|youtube|singles|surgery|alibaba|statoil|dentist|schwarz|android|cruises|cricket|digital|markets|starhub|systems|courses|coupons|netbank|country|domains|corsica|network|neustar|realtor|lincoln|limited|schmidt|yamaxun|cooking|contact|auction|spiegel|liaison|leclerc|latrobe|lasalle|abogado|compare|lanxess|exposed|express|company|cologne|college|avianca|lacaixa|fashion|recipes|ferrero|komatsu|storage|wanggou|clubmed|sandvik|fishing|fitness|bauhaus|kitchen|flights|florist|flowers|watches|weather|temasek|samsung|bentley|forsale|channel|theater|frogans|theatre|okinawa|website|tickets|jewelry|gallery|tiffany|iselect|shiksha|brother|organic|wedding|genting|toshiba|origins|philips|hyundai|hotmail|hoteles|hosting|rentals|windows|cartier|bugatti|holiday|careers|whoswho|hitachi|panerai|caravan|reviews|guitars|capital|trading|hamburg|hangout|finance|stream|family|abbott|health|review|travel|report|hermes|hiphop|gratis|career|toyota|hockey|dating|repair|google|social|soccer|reisen|global|otsuka|giving|unicom|casino|photos|center|broker|rocher|orange|bostik|garden|insure|ryukyu|bharti|safety|physio|sakura|oracle|online|jaguar|gallup|piaget|tienda|futbol|pictet|joburg|webcam|berlin|office|juegos|kaufen|chanel|chrome|xihuan|church|tennis|circle|kinder|flickr|bayern|claims|clinic|viajes|nowruz|xperia|norton|yachts|studio|coffee|camera|sanofi|nissan|author|expert|events|comsec|lawyer|tattoo|viking|estate|villas|condos|realty|yandex|energy|emerck|virgin|vision|durban|living|school|coupon|london|taobao|natura|taipei|nagoya|luxury|walter|aramco|sydney|madrid|credit|maison|makeup|schule|market|anquan|direct|design|swatch|suzuki|alsace|vuelos|dental|alipay|voyage|shouji|voting|airtel|mutual|degree|supply|agency|museum|mobily|dealer|monash|select|mormon|active|moscow|racing|datsun|quebec|nissay|rodeo|email|gifts|works|photo|chloe|edeka|cheap|earth|vista|tushu|koeln|glass|shoes|globo|tunes|gmail|nokia|space|kyoto|black|ricoh|seven|lamer|sener|epson|cisco|praxi|trust|citic|crown|shell|lease|green|legal|lexus|ninja|tatar|gripe|nikon|group|video|wales|autos|gucci|party|nexus|guide|linde|adult|parts|amica|lixil|boats|azure|loans|locus|cymru|lotte|lotto|stada|click|poker|quest|dabur|lupin|nadex|paris|faith|dance|canon|place|gives|trade|skype|rocks|mango|cloud|boots|smile|final|swiss|homes|honda|media|horse|cards|deals|watch|bosch|house|pizza|miami|osaka|tours|total|xerox|coach|sucks|style|delta|toray|iinet|tools|money|codes|beats|tokyo|salon|archi|movie|baidu|study|actor|yahoo|store|apple|world|forex|today|bible|tmall|tirol|irish|tires|forum|reise|vegas|vodka|sharp|omega|weber|jetzt|audio|promo|build|bingo|chase|gallo|drive|dubai|rehab|press|solar|sale|beer|bbva|bank|band|auto|sapo|sarl|saxo|audi|asia|arte|arpa|army|yoga|ally|zara|scor|scot|sexy|seat|zero|seek|aero|adac|zone|aarp|maif|meet|meme|menu|surf|mini|mobi|mtpc|porn|desi|star|ltda|name|talk|navy|love|loan|live|link|news|limo|like|spot|life|nico|lidl|lgbt|land|taxi|team|tech|kred|kpmg|sony|song|kiwi|kddi|jprs|jobs|sohu|java|itau|tips|info|immo|icbc|hsbc|town|host|page|toys|here|help|pars|haus|guru|guge|tube|goog|golf|gold|sncf|gmbh|gift|ggee|gent|gbiz|game|vana|pics|fund|ford|ping|pink|fish|film|fast|farm|play|fans|fail|plus|skin|pohl|fage|moda|post|erni|dvag|prod|doha|prof|docs|viva|diet|luxe|site|dell|sina|dclk|show|qpon|date|vote|cyou|voto|read|coop|cool|wang|club|city|chat|cern|cash|reit|rent|casa|cars|care|camp|rest|call|cafe|weir|wien|rich|wiki|buzz|wine|book|bond|room|work|rsvp|shia|ruhr|blue|bing|shaw|bike|safe|xbox|best|pwc|mtn|lds|aig|boo|fyi|nra|nrw|ntt|car|gal|obi|zip|aeg|vin|how|one|ong|onl|dad|ooo|bet|esq|org|htc|bar|uol|ibm|ovh|gdn|ice|icu|uno|gea|ifm|bot|top|wtf|lol|day|pet|eus|wtc|ubs|tvs|aco|ing|ltd|ink|tab|abb|afl|cat|int|pid|pin|bid|cba|gle|com|cbn|ads|man|wed|ceb|gmo|sky|ist|gmx|tui|mba|fan|ski|iwc|app|pro|med|ceo|jcb|jcp|goo|dev|men|aaa|meo|pub|jlc|bom|jll|gop|jmp|mil|got|gov|win|jot|mma|joy|trv|red|cfa|cfd|bio|moe|moi|mom|ren|biz|aws|xin|bbc|dnp|buy|kfh|mov|thd|xyz|fit|kia|rio|rip|kim|dog|vet|nyc|bcg|mtr|bcn|bms|bmw|run|bzh|rwe|tel|stc|axa|kpn|fly|krd|cab|bnl|foo|crs|eat|tci|sap|srl|nec|sas|net|cal|sbs|sfr|sca|scb|csc|edu|new|xxx|hiv|fox|wme|ngo|nhk|vip|sex|frl|lat|yun|law|you|tax|soy|sew|om|ac|hu|se|sc|sg|sh|sb|sa|rw|ru|rs|ro|re|qa|py|si|pw|pt|ps|sj|sk|pr|pn|pm|pl|sl|sm|pk|sn|ph|so|pg|pf|pe|pa|zw|nz|nu|nr|np|no|nl|ni|ng|nf|sr|ne|st|nc|na|mz|my|mx|mw|mv|mu|mt|ms|mr|mq|mp|mo|su|mn|mm|ml|mk|mh|mg|me|sv|md|mc|sx|sy|ma|ly|lv|sz|lu|lt|ls|lr|lk|li|lc|lb|la|tc|kz|td|ky|kw|kr|kp|kn|km|ki|kh|tf|tg|th|kg|ke|jp|jo|jm|je|it|is|ir|tj|tk|tl|tm|iq|tn|to|io|in|im|il|ie|ad|sd|ht|hr|hn|hm|tr|hk|gy|gw|gu|gt|gs|gr|gq|tt|gp|gn|gm|gl|tv|gi|tw|tz|ua|gh|ug|uk|gg|gf|ge|gd|us|uy|uz|va|gb|ga|vc|ve|fr|fo|fm|fk|fj|vg|vi|fi|eu|et|es|er|eg|ee|ec|dz|do|dm|dk|vn|dj|de|cz|cy|cx|cw|vu|cv|cu|cr|co|cn|cm|cl|ck|ci|ch|cg|cf|cd|cc|ca|wf|bz|by|bw|bv|bt|bs|br|bo|bn|bm|bj|bi|ws|bh|bg|bf|be|bd|bb|ba|az|ax|aw|au|at|as|ye|ar|aq|ao|am|al|yt|ai|za|ag|af|ae|zm|id)\\b/;return{alphaNumericCharsStr:r,domainNameRegex:a,tldRegex:n}}(),t.AnchorTagBuilder=t.Util.extend(Object,{constructor:function(t){t=t||{},this.newWindow=t.newWindow,this.truncate=t.truncate,this.className=t.className},build:function(e){return new t.HtmlTag({tagName:"a",attrs:this.createAttrs(e),innerHtml:this.processAnchorText(e.getAnchorText())})},createAttrs:function(t){var e={href:t.getAnchorHref()},r=this.createCssClass(t);return r&&(e["class"]=r),this.newWindow&&(e.target="_blank",e.rel="noopener noreferrer"),e},createCssClass:function(t){var e=this.className;if(e){for(var r=[e],a=t.getCssClassSuffixes(),n=0,i=a.length;n<i;n++)r.push(e+"-"+a[n]);return r.join(" ")}return""},processAnchorText:function(t){return t=this.doTruncate(t)},doTruncate:function(e){var r=this.truncate;if(!r||!r.length)return e;var a=r.length,n=r.location;return"smart"===n?t.truncate.TruncateSmart(e,a,".."):"middle"===n?t.truncate.TruncateMiddle(e,a,".."):t.truncate.TruncateEnd(e,a,"..")}}),t.htmlParser.HtmlParser=t.Util.extend(Object,{htmlRegex:function(){var t=/!--([\\s\\S]+?)--/,e=/[0-9a-zA-Z][0-9a-zA-Z:]*/,r=/[^\\s"'>\\/=\\x00-\\x1F\\x7F]+/,a=/(?:"[^"]*?"|'[^']*?'|[^'"=<>`\\s]+)/,n=r.source+"(?:\\\\s*=\\\\s*"+a.source+")?";return new RegExp(["(?:","<(!DOCTYPE)","(?:","\\\\s+","(?:",n,"|",a.source+")",")*",">",")","|","(?:","<(/)?","(?:",t.source,"|","(?:","("+e.source+")","\\\\s*/?",")","|","(?:","("+e.source+")","\\\\s+","(?:","(?:\\\\s+|\\\\b)",n,")*","\\\\s*/?",")",")",">",")"].join(""),"gi")}(),htmlCharacterEntitiesRegex:/(&nbsp;|&#160;|&lt;|&#60;|&gt;|&#62;|&quot;|&#34;|&#39;)/gi,parse:function(t){for(var e,r,a=this.htmlRegex,n=0,i=[];null!==(e=a.exec(t));){var s=e[0],o=e[3],c=e[1]||e[4]||e[5],h=!!e[2],l=e.index,u=t.substring(n,l);u&&(r=this.parseTextAndEntityNodes(n,u),i.push.apply(i,r)),o?i.push(this.createCommentNode(l,s,o)):i.push(this.createElementNode(l,s,c,h)),n=l+s.length}if(n<t.length){var g=t.substring(n);g&&(r=this.parseTextAndEntityNodes(n,g),r.forEach(function(t){i.push(t)}))}return i},parseTextAndEntityNodes:function(e,r){for(var a=[],n=t.Util.splitAndCapture(r,this.htmlCharacterEntitiesRegex),i=0,s=n.length;i<s;i+=2){var o=n[i],c=n[i+1];o&&(a.push(this.createTextNode(e,o)),e+=o.length),c&&(a.push(this.createEntityNode(e,c)),e+=c.length)}return a},createCommentNode:function(e,r,a){return new t.htmlParser.CommentNode({offset:e,text:r,comment:t.Util.trim(a)})},createElementNode:function(e,r,a,n){return new t.htmlParser.ElementNode({offset:e,text:r,tagName:a.toLowerCase(),closing:n})},createEntityNode:function(e,r){return new t.htmlParser.EntityNode({offset:e,text:r})},createTextNode:function(e,r){return new t.htmlParser.TextNode({offset:e,text:r})}}),t.htmlParser.HtmlNode=t.Util.extend(Object,{offset:void 0,text:void 0,constructor:function(e){t.Util.assign(this,e)},getType:t.Util.abstractMethod,getOffset:function(){return this.offset},getText:function(){return this.text}}),t.htmlParser.CommentNode=t.Util.extend(t.htmlParser.HtmlNode,{comment:"",getType:function(){return"comment"},getComment:function(){return this.comment}}),t.htmlParser.ElementNode=t.Util.extend(t.htmlParser.HtmlNode,{tagName:"",closing:!1,getType:function(){return"element"},getTagName:function(){return this.tagName},isClosing:function(){return this.closing}}),t.htmlParser.EntityNode=t.Util.extend(t.htmlParser.HtmlNode,{getType:function(){return"entity"}}),t.htmlParser.TextNode=t.Util.extend(t.htmlParser.HtmlNode,{getType:function(){return"text"}}),t.match.Match=t.Util.extend(Object,{constructor:function(t){this.tagBuilder=t.tagBuilder,this.matchedText=t.matchedText,this.offset=t.offset},getType:t.Util.abstractMethod,getMatchedText:function(){return this.matchedText},setOffset:function(t){this.offset=t},getOffset:function(){return this.offset},getAnchorHref:t.Util.abstractMethod,getAnchorText:t.Util.abstractMethod,getCssClassSuffixes:function(){return[this.getType()]},buildTag:function(){return this.tagBuilder.build(this)}}),t.match.Email=t.Util.extend(t.match.Match,{constructor:function(e){t.match.Match.prototype.constructor.call(this,e),this.email=e.email},getType:function(){return"email"},getEmail:function(){return this.email},getAnchorHref:function(){return"mailto:"+this.email},getAnchorText:function(){return this.email}}),t.match.Hashtag=t.Util.extend(t.match.Match,{constructor:function(e){t.match.Match.prototype.constructor.call(this,e),this.serviceName=e.serviceName,this.hashtag=e.hashtag},getType:function(){return"hashtag"},getServiceName:function(){return this.serviceName},getHashtag:function(){return this.hashtag},getAnchorHref:function(){var t=this.serviceName,e=this.hashtag;switch(t){case"twitter":return"https://twitter.com/hashtag/"+e;case"facebook":return"https://www.facebook.com/hashtag/"+e;case"instagram":return"https://instagram.com/explore/tags/"+e;default:throw new Error("Unknown service name to point hashtag to: ",t)}},getAnchorText:function(){return"#"+this.hashtag}}),t.match.Phone=t.Util.extend(t.match.Match,{constructor:function(e){t.match.Match.prototype.constructor.call(this,e),this.number=e.number,this.plusSign=e.plusSign},getType:function(){return"phone"},getNumber:function(){return this.number},getAnchorHref:function(){return"tel:"+(this.plusSign?"+":"")+this.number},getAnchorText:function(){return this.matchedText}}),t.match.Mention=t.Util.extend(t.match.Match,{constructor:function(e){t.match.Match.prototype.constructor.call(this,e),this.mention=e.mention,this.serviceName=e.serviceName},getType:function(){return"mention"},getMention:function(){return this.mention},getServiceName:function(){return this.serviceName},getAnchorHref:function(){switch(this.serviceName){case"twitter":return"https://twitter.com/"+this.mention;case"instagram":return"https://instagram.com/"+this.mention;default:throw new Error("Unknown service name to point mention to: ",this.serviceName)}},getAnchorText:function(){return"@"+this.mention},getCssClassSuffixes:function(){var e=t.match.Match.prototype.getCssClassSuffixes.call(this),r=this.getServiceName();return r&&e.push(r),e}}),t.match.Url=t.Util.extend(t.match.Match,{constructor:function(e){t.match.Match.prototype.constructor.call(this,e),this.urlMatchType=e.urlMatchType,this.url=e.url,this.protocolUrlMatch=e.protocolUrlMatch,this.protocolRelativeMatch=e.protocolRelativeMatch,this.stripPrefix=e.stripPrefix,this.stripTrailingSlash=e.stripTrailingSlash},schemePrefixRegex:/^(https?:\\/\\/)?/i,wwwPrefixRegex:/^(https?:\\/\\/)?(www\\.)?/i,protocolRelativeRegex:/^\\/\\//,protocolPrepended:!1,getType:function(){return"url"},getUrlMatchType:function(){return this.urlMatchType},getUrl:function(){var t=this.url;return this.protocolRelativeMatch||this.protocolUrlMatch||this.protocolPrepended||(t=this.url="http://"+t,this.protocolPrepended=!0),t},getAnchorHref:function(){var t=this.getUrl();return t.replace(/&amp;/g,"&")},getAnchorText:function(){var t=this.getMatchedText();return this.protocolRelativeMatch&&(t=this.stripProtocolRelativePrefix(t)),this.stripPrefix.scheme&&(t=this.stripSchemePrefix(t)),this.stripPrefix.www&&(t=this.stripWwwPrefix(t)),this.stripTrailingSlash&&(t=this.removeTrailingSlash(t)),t},stripSchemePrefix:function(t){return t.replace(this.schemePrefixRegex,"")},stripWwwPrefix:function(t){return t.replace(this.wwwPrefixRegex,"$1")},stripProtocolRelativePrefix:function(t){return t.replace(this.protocolRelativeRegex,"")},removeTrailingSlash:function(t){return"/"===t.charAt(t.length-1)&&(t=t.slice(0,-1)),t}}),t.matcher.Matcher=t.Util.extend(Object,{constructor:function(t){this.tagBuilder=t.tagBuilder},parseMatches:t.Util.abstractMethod}),t.matcher.Email=t.Util.extend(t.matcher.Matcher,{matcherRegex:function(){var e=t.RegexLib.alphaNumericCharsStr,r=new RegExp("["+e+"\\\\-_';:&=+$.,]+@"),a=t.RegexLib.domainNameRegex,n=t.RegexLib.tldRegex;return new RegExp([r.source,a.source,"\\\\.",n.source].join(""),"gi")}(),parseMatches:function(e){for(var r,a=this.matcherRegex,n=this.tagBuilder,i=[];null!==(r=a.exec(e));){var s=r[0];i.push(new t.match.Email({tagBuilder:n,matchedText:s,offset:r.index,email:s}))}return i}}),t.matcher.Hashtag=t.Util.extend(t.matcher.Matcher,{matcherRegex:new RegExp("#[_"+t.RegexLib.alphaNumericCharsStr+"]{1,139}","g"),nonWordCharRegex:new RegExp("[^"+t.RegexLib.alphaNumericCharsStr+"]"),constructor:function(e){t.matcher.Matcher.prototype.constructor.call(this,e),this.serviceName=e.serviceName},parseMatches:function(e){for(var r,a=this.matcherRegex,n=this.nonWordCharRegex,i=this.serviceName,s=this.tagBuilder,o=[];null!==(r=a.exec(e));){var c=r.index,h=e.charAt(c-1);if(0===c||n.test(h)){var l=r[0],u=r[0].slice(1);o.push(new t.match.Hashtag({tagBuilder:s,matchedText:l,offset:c,serviceName:i,hashtag:u}))}}return o}}),t.matcher.Phone=t.Util.extend(t.matcher.Matcher,{matcherRegex:/(?:(\\+)?\\d{1,3}[-\\040.])?\\(?\\d{3}\\)?[-\\040.]?\\d{3}[-\\040.]\\d{4}/g,parseMatches:function(e){for(var r,a=this.matcherRegex,n=this.tagBuilder,i=[];null!==(r=a.exec(e));){var s=r[0],o=s.replace(/\\D/g,""),c=!!r[1];i.push(new t.match.Phone({tagBuilder:n,matchedText:s,offset:r.index,number:o,plusSign:c}))}return i}}),t.matcher.Mention=t.Util.extend(t.matcher.Matcher,{matcherRegexes:{twitter:new RegExp("@[_"+t.RegexLib.alphaNumericCharsStr+"]{1,20}","g"),instagram:new RegExp("@[_."+t.RegexLib.alphaNumericCharsStr+"]{1,50}","g")},nonWordCharRegex:new RegExp("[^"+t.RegexLib.alphaNumericCharsStr+"]"),constructor:function(e){t.matcher.Matcher.prototype.constructor.call(this,e),this.serviceName=e.serviceName},parseMatches:function(e){var r,a=this.matcherRegexes[this.serviceName],n=this.nonWordCharRegex,i=this.serviceName,s=this.tagBuilder,o=[];if(!a)return o;for(;null!==(r=a.exec(e));){var c=r.index,h=e.charAt(c-1);if(0===c||n.test(h)){var l=r[0].replace(/\\.+$/g,""),u=l.slice(1);o.push(new t.match.Mention({tagBuilder:s,matchedText:l,offset:c,serviceName:i,mention:u}))}}return o}}),t.matcher.Url=t.Util.extend(t.matcher.Matcher,{matcherRegex:function(){var e=/(?:[A-Za-z][-.+A-Za-z0-9]*:(?![A-Za-z][-.+A-Za-z0-9]*:\\/\\/)(?!\\d+\\/?)(?:\\/\\/)?)/,r=/(?:www\\.)/,a=t.RegexLib.domainNameRegex,n=t.RegexLib.tldRegex,i=t.RegexLib.alphaNumericCharsStr,s=new RegExp("["+i+"\\\\-+&@#/%=~_()|'$*\\\\[\\\\]?!:,.;✓]*["+i+"\\\\-+&@#/%=~_()|'$*\\\\[\\\\]✓]");return new RegExp(["(?:","(",e.source,a.source,")","|","(","(//)?",r.source,a.source,")","|","(","(//)?",a.source+"\\\\.",n.source,")",")","(?:"+s.source+")?"].join(""),"gi")}(),wordCharRegExp:/\\w/,openParensRe:/\\(/g,closeParensRe:/\\)/g,constructor:function(e){t.matcher.Matcher.prototype.constructor.call(this,e),this.stripPrefix=e.stripPrefix,this.stripTrailingSlash=e.stripTrailingSlash},parseMatches:function(e){for(var r,a=this.matcherRegex,n=this.stripPrefix,i=this.stripTrailingSlash,s=this.tagBuilder,o=[];null!==(r=a.exec(e));){var c=r[0],h=r[1],l=r[2],u=r[3],g=r[5],m=r.index,f=u||g,p=e.charAt(m-1);if(t.matcher.UrlMatchValidator.isValid(c,h)&&!(m>0&&"@"===p||m>0&&f&&this.wordCharRegExp.test(p))){if(this.matchHasUnbalancedClosingParen(c))c=c.substr(0,c.length-1);else{var d=this.matchHasInvalidCharAfterTld(c,h);d>-1&&(c=c.substr(0,d))}var b=h?"scheme":l?"www":"tld",x=!!h;o.push(new t.match.Url({tagBuilder:s,matchedText:c,offset:m,urlMatchType:b,url:c,protocolUrlMatch:x,protocolRelativeMatch:!!f,stripPrefix:n,stripTrailingSlash:i}))}}return o},matchHasUnbalancedClosingParen:function(t){var e=t.charAt(t.length-1);if(")"===e){var r=t.match(this.openParensRe),a=t.match(this.closeParensRe),n=r&&r.length||0,i=a&&a.length||0;if(n<i)return!0}return!1},matchHasInvalidCharAfterTld:function(t,e){if(!t)return-1;var r=0;e&&(r=t.indexOf(":"),t=t.slice(r));var a=/^((.?\\/\\/)?[A-Za-z0-9\\u00C0-\\u017F\\.\\-]*[A-Za-z0-9\\u00C0-\\u017F\\-]\\.[A-Za-z]+)/,n=a.exec(t);return null===n?-1:(r+=n[1].length,t=t.slice(n[1].length),/^[^.A-Za-z0-9:\\/?#]/.test(t)?r:-1)}}),t.matcher.UrlMatchValidator={hasFullProtocolRegex:/^[A-Za-z][-.+A-Za-z0-9]*:\\/\\//,uriSchemeRegex:/^[A-Za-z][-.+A-Za-z0-9]*:/,hasWordCharAfterProtocolRegex:/:[^\\s]*?[A-Za-z\\u00C0-\\u017F]/,ipRegex:/[0-9][0-9]?[0-9]?\\.[0-9][0-9]?[0-9]?\\.[0-9][0-9]?[0-9]?\\.[0-9][0-9]?[0-9]?(:[0-9]*)?\\/?$/,isValid:function(t,e){return!(e&&!this.isValidUriScheme(e)||this.urlMatchDoesNotHaveProtocolOrDot(t,e)||this.urlMatchDoesNotHaveAtLeastOneWordChar(t,e)&&!this.isValidIpAddress(t)||this.containsMultipleDots(t))},isValidIpAddress:function(t){var e=new RegExp(this.hasFullProtocolRegex.source+this.ipRegex.source),r=t.match(e);return null!==r},containsMultipleDots:function(t){return t.indexOf("..")>-1},isValidUriScheme:function(t){var e=t.match(this.uriSchemeRegex)[0].toLowerCase();return"javascript:"!==e&&"vbscript:"!==e},urlMatchDoesNotHaveProtocolOrDot:function(t,e){return!(!t||e&&this.hasFullProtocolRegex.test(e)||t.indexOf(".")!==-1)},urlMatchDoesNotHaveAtLeastOneWordChar:function(t,e){return!(!t||!e)&&!this.hasWordCharAfterProtocolRegex.test(t)}},t.truncate.TruncateEnd=function(e,r,a){return t.Util.ellipsis(e,r,a)},t.truncate.TruncateMiddle=function(t,e,r){if(t.length<=e)return t;var a=e-r.length,n="";return a>0&&(n=t.substr(-1*Math.floor(a/2))),(t.substr(0,Math.ceil(a/2))+r+n).substr(0,e)},t.truncate.TruncateSmart=function(t,e,r){var a=function(t){var e={},r=t,a=r.match(/^([a-z]+):\\/\\//i);return a&&(e.scheme=a[1],r=r.substr(a[0].length)),a=r.match(/^(.*?)(?=(\\?|#|\\/|$))/i),a&&(e.host=a[1],r=r.substr(a[0].length)),a=r.match(/^\\/(.*?)(?=(\\?|#|$))/i),a&&(e.path=a[1],r=r.substr(a[0].length)),a=r.match(/^\\?(.*?)(?=(#|$))/i),a&&(e.query=a[1],r=r.substr(a[0].length)),a=r.match(/^#(.*?)$/i),a&&(e.fragment=a[1]),e},n=function(t){var e="";return t.scheme&&t.host&&(e+=t.scheme+"://"),t.host&&(e+=t.host),t.path&&(e+="/"+t.path),t.query&&(e+="?"+t.query),t.fragment&&(e+="#"+t.fragment),e},i=function(t,e){var a=e/2,n=Math.ceil(a),i=-1*Math.floor(a),s="";return i<0&&(s=t.substr(i)),t.substr(0,n)+r+s};if(t.length<=e)return t;var s=e-r.length,o=a(t);if(o.query){var c=o.query.match(/^(.*?)(?=(\\?|\\#))(.*?)$/i);c&&(o.query=o.query.substr(0,c[1].length),t=n(o))}if(t.length<=e)return t;if(o.host&&(o.host=o.host.replace(/^www\\./,""),t=n(o)),t.length<=e)return t;var h="";if(o.host&&(h+=o.host),h.length>=s)return o.host.length==e?(o.host.substr(0,e-r.length)+r).substr(0,e):i(h,s).substr(0,e);var l="";if(o.path&&(l+="/"+o.path),o.query&&(l+="?"+o.query),l){if((h+l).length>=s){if((h+l).length==e)return(h+l).substr(0,e);var u=s-h.length;return(h+i(l,u)).substr(0,e)}h+=l}if(o.fragment){var g="#"+o.fragment;if((h+g).length>=s){if((h+g).length==e)return(h+g).substr(0,e);var m=s-h.length;return(h+i(g,m)).substr(0,e)}h+=g}if(o.scheme&&o.host){var f=o.scheme+"://";if((h+f).length<s)return(f+h).substr(0,e)}if(h.length<=e)return h;var p="";return s>0&&(p=h.substr(-1*Math.floor(s/2))),(h.substr(0,Math.ceil(s/2))+r+p).substr(0,e)},t});
END;
}