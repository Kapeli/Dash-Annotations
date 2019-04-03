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
                if(smart_count($team_ids))
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
            if(smart_count($public_entries))
            {
                $response["public_entries"] = $public_entries;
            }
            if(smart_count($own_entries))
            {
                $response["own_entries"] = $own_entries;
            }
            if(smart_count($team_entries))
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
                if(!$entry->public && !smart_count($my_teams) && $entry->user_id != $user->id)
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
                        body.dash-dark {
                            color: white;
                        }
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

                        code, kbd, pre, samp {
                            font-family: "SF Mono", Menlo, Monaco, Consolas, "Courier New", monospace;
                        }

                        
                        @font-face {
                            font-family: "SF Mono";
                            src: local("SFMono-Light");
                            font-weight: 100;
                            font-style: normal;
                        }

                        @font-face {
                            font-family: "SF Mono";
                            src: local("SFMono-Light");
                            font-weight: 200;
                            font-style: normal;
                        }

                        @font-face {
                            font-family: "SF Mono";
                            src: local("SFMono-Light");
                            font-weight: 300;
                            font-style: normal;
                        }

                        @font-face {
                            font-family: "SF Mono";
                            src: local("SFMono-Regular");
                            font-weight: 400;
                            font-style: normal;
                        }

                        @font-face {
                            font-family: "SF Mono";
                            src: local("SFMono-Medium");
                            font-weight: 500;
                            font-style: normal;
                        }

                        @font-face {
                            font-family: "SF Mono";
                            src: local("SFMono-Semibold");
                            font-weight: 600;
                            font-style: normal;
                        }

                        @font-face {
                            font-family: "SF Mono";
                            src: local("SFMono-Bold");
                            font-weight: 700;
                            font-style: normal;
                        }

                        @font-face {
                            font-family: "SF Mono";
                            src: local("SFMono-Bold");
                            font-weight: 800;
                            font-style: normal;
                        }

                        @font-face {
                            font-family: "SF Mono";
                            src: local("SFMono-Heavy");
                            font-weight: 900;
                            font-style: normal;
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

                        blockquote {
                            padding: 0 1em;
                            color: #555;
                            border-left: 0.25em solid #999;
                            margin-left: 0;
                            font-size:inherit;
                            margin-bottom: 10px;
                        }

                        .dash-dark blockquote {
                            color: #ddd;
                        }
                    </style>
                </head>
                <body>
                    <div class="container-fluid">';

            if($entry->public || smart_count($my_teams))
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
                    $team_string .= (smart_count($my_teams) == $i) ? " and " : ", ";
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
                    if(smart_count($my_teams) > 1)
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

function smart_count($array)
{
    $count = @count($array);
    return $count;
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
     * 1.7.1
     *
     * Copyright(c) 2018 Gregory Jacobs <greg@greg-jacobs.com>
     * MIT License
     *
     * https://github.com/gregjacobs/Autolinker.js
     */
    !function(e,t){"function"==typeof define&&define.amd?define([],t):"object"==typeof exports?module.exports=t():e.Autolinker=t()}(this,function(){var e=function(t){t=t||{},this.version=e.version,this.urls=this.normalizeUrlsCfg(t.urls),this.email="boolean"!=typeof t.email||t.email,this.phone="boolean"!=typeof t.phone||t.phone,this.hashtag=t.hashtag||!1,this.mention=t.mention||!1,this.newWindow="boolean"!=typeof t.newWindow||t.newWindow,this.stripPrefix=this.normalizeStripPrefixCfg(t.stripPrefix),this.stripTrailingSlash="boolean"!=typeof t.stripTrailingSlash||t.stripTrailingSlash,this.decodePercentEncoding="boolean"!=typeof t.decodePercentEncoding||t.decodePercentEncoding;var r=this.mention;if(r!==!1&&"twitter"!==r&&"instagram"!==r)throw new Error("invalid `mention` cfg - see docs");var n=this.hashtag;if(n!==!1&&"twitter"!==n&&"facebook"!==n&&"instagram"!==n)throw new Error("invalid `hashtag` cfg - see docs");this.truncate=this.normalizeTruncateCfg(t.truncate),this.className=t.className||"",this.replaceFn=t.replaceFn||null,this.context=t.context||this,this.htmlParser=null,this.matchers=null,this.tagBuilder=null};return e.link=function(t,r){var n=new e(r);return n.link(t)},e.parse=function(t,r){var n=new e(r);return n.parse(t)},e.version="1.7.1",e.prototype={constructor:e,normalizeUrlsCfg:function(e){return null==e&&(e=!0),"boolean"==typeof e?{schemeMatches:e,wwwMatches:e,tldMatches:e}:{schemeMatches:"boolean"!=typeof e.schemeMatches||e.schemeMatches,wwwMatches:"boolean"!=typeof e.wwwMatches||e.wwwMatches,tldMatches:"boolean"!=typeof e.tldMatches||e.tldMatches}},normalizeStripPrefixCfg:function(e){return null==e&&(e=!0),"boolean"==typeof e?{scheme:e,www:e}:{scheme:"boolean"!=typeof e.scheme||e.scheme,www:"boolean"!=typeof e.www||e.www}},normalizeTruncateCfg:function(t){return"number"==typeof t?{length:t,location:"end"}:e.Util.defaults(t||{},{length:Number.POSITIVE_INFINITY,location:"end"})},parse:function(e){for(var t=this.getHtmlParser(),r=t.parse(e),n=0,a=[],i=0,s=r.length;i<s;i++){var o=r[i],c=o.getType();if("element"===c&&"a"===o.getTagName())o.isClosing()?n=Math.max(n-1,0):n++;else if("text"===c&&0===n){var h=this.parseText(o.getText(),o.getOffset());a.push.apply(a,h)}}return a=this.compactMatches(a),a=this.removeUnwantedMatches(a)},compactMatches:function(e){e.sort(function(e,t){return e.getOffset()-t.getOffset()});for(var t=0;t<e.length-1;t++){var r=e[t],n=r.getOffset(),a=r.getMatchedText().length,i=n+a;if(t+1<e.length){if(e[t+1].getOffset()===n){var s=e[t+1].getMatchedText().length>a?t:t+1;e.splice(s,1);continue}e[t+1].getOffset()<i&&e.splice(t+1,1)}}return e},removeUnwantedMatches:function(t){var r=e.Util.remove;return this.hashtag||r(t,function(e){return"hashtag"===e.getType()}),this.email||r(t,function(e){return"email"===e.getType()}),this.phone||r(t,function(e){return"phone"===e.getType()}),this.mention||r(t,function(e){return"mention"===e.getType()}),this.urls.schemeMatches||r(t,function(e){return"url"===e.getType()&&"scheme"===e.getUrlMatchType()}),this.urls.wwwMatches||r(t,function(e){return"url"===e.getType()&&"www"===e.getUrlMatchType()}),this.urls.tldMatches||r(t,function(e){return"url"===e.getType()&&"tld"===e.getUrlMatchType()}),t},parseText:function(e,t){t=t||0;for(var r=this.getMatchers(),n=[],a=0,i=r.length;a<i;a++){for(var s=r[a].parseMatches(e),o=0,c=s.length;o<c;o++)s[o].setOffset(t+s[o].getOffset());n.push.apply(n,s)}return n},link:function(e){if(!e)return"";for(var t=this.parse(e),r=[],n=0,a=0,i=t.length;a<i;a++){var s=t[a];r.push(e.substring(n,s.getOffset())),r.push(this.createMatchReturnVal(s)),n=s.getOffset()+s.getMatchedText().length}return r.push(e.substring(n)),r.join("")},createMatchReturnVal:function(t){var r;if(this.replaceFn&&(r=this.replaceFn.call(this.context,t)),"string"==typeof r)return r;if(r===!1)return t.getMatchedText();if(r instanceof e.HtmlTag)return r.toAnchorString();var n=t.buildTag();return n.toAnchorString()},getHtmlParser:function(){var t=this.htmlParser;return t||(t=this.htmlParser=new e.htmlParser.HtmlParser),t},getMatchers:function(){if(this.matchers)return this.matchers;var t=e.matcher,r=this.getTagBuilder(),n=[new t.Hashtag({tagBuilder:r,serviceName:this.hashtag}),new t.Email({tagBuilder:r}),new t.Phone({tagBuilder:r}),new t.Mention({tagBuilder:r,serviceName:this.mention}),new t.Url({tagBuilder:r,stripPrefix:this.stripPrefix,stripTrailingSlash:this.stripTrailingSlash,decodePercentEncoding:this.decodePercentEncoding})];return this.matchers=n},getTagBuilder:function(){var t=this.tagBuilder;return t||(t=this.tagBuilder=new e.AnchorTagBuilder({newWindow:this.newWindow,truncate:this.truncate,className:this.className})),t}},e.match={},e.matcher={},e.htmlParser={},e.truncate={},e.Util={abstractMethod:function(){throw"abstract"},trimRegex:/^[\\s\\uFEFF\\xA0]+|[\\s\\uFEFF\\xA0]+$/g,assign:function(e,t){for(var r in t)t.hasOwnProperty(r)&&(e[r]=t[r]);return e},defaults:function(e,t){for(var r in t)t.hasOwnProperty(r)&&void 0===e[r]&&(e[r]=t[r]);return e},extend:function(t,r){var n=t.prototype,a=function(){};a.prototype=n;var i;i=r.hasOwnProperty("constructor")?r.constructor:function(){n.constructor.apply(this,arguments)};var s=i.prototype=new a;return s.constructor=i,s.superclass=n,delete r.constructor,e.Util.assign(s,r),i},ellipsis:function(e,t,r){var n;return e.length>t&&(null==r?(r="&hellip;",n=3):n=r.length,e=e.substring(0,t-n)+r),e},indexOf:function(e,t){if(Array.prototype.indexOf)return e.indexOf(t);for(var r=0,n=e.length;r<n;r++)if(e[r]===t)return r;return-1},remove:function(e,t){for(var r=e.length-1;r>=0;r--)t(e[r])===!0&&e.splice(r,1)},splitAndCapture:function(e,t){for(var r,n=[],a=0;r=t.exec(e);)n.push(e.substring(a,r.index)),n.push(r[0]),a=r.index+r[0].length;return n.push(e.substring(a)),n},trim:function(e){return e.replace(this.trimRegex,"")}},e.HtmlTag=e.Util.extend(Object,{whitespaceRegex:/\\s+/,constructor:function(t){e.Util.assign(this,t),this.innerHtml=this.innerHtml||this.innerHTML},setTagName:function(e){return this.tagName=e,this},getTagName:function(){return this.tagName||""},setAttr:function(e,t){var r=this.getAttrs();return r[e]=t,this},getAttr:function(e){return this.getAttrs()[e]},setAttrs:function(t){var r=this.getAttrs();return e.Util.assign(r,t),this},getAttrs:function(){return this.attrs||(this.attrs={})},setClass:function(e){return this.setAttr("class",e)},addClass:function(t){for(var r,n=this.getClass(),a=this.whitespaceRegex,i=e.Util.indexOf,s=n?n.split(a):[],o=t.split(a);r=o.shift();)i(s,r)===-1&&s.push(r);return this.getAttrs()["class"]=s.join(" "),this},removeClass:function(t){for(var r,n=this.getClass(),a=this.whitespaceRegex,i=e.Util.indexOf,s=n?n.split(a):[],o=t.split(a);s.length&&(r=o.shift());){var c=i(s,r);c!==-1&&s.splice(c,1)}return this.getAttrs()["class"]=s.join(" "),this},getClass:function(){return this.getAttrs()["class"]||""},hasClass:function(e){return(" "+this.getClass()+" ").indexOf(" "+e+" ")!==-1},setInnerHtml:function(e){return this.innerHtml=e,this},getInnerHtml:function(){return this.innerHtml||""},toAnchorString:function(){var e=this.getTagName(),t=this.buildAttrsStr();return t=t?" "+t:"",["<",e,t,">",this.getInnerHtml(),"</",e,">"].join("")},buildAttrsStr:function(){if(!this.attrs)return"";var e=this.getAttrs(),t=[];for(var r in e)e.hasOwnProperty(r)&&t.push(r+'="'+e[r]+'"');return t.join(" ")}}),e.RegexLib=function(){var e="A-Za-z\\\\xAA\\\\xB5\\\\xBA\\\\xC0-\\\\xD6\\\\xD8-\\\\xF6\\\\xF8-ˁˆ-ˑˠ-ˤˬˮͰ-ʹͶͷͺ-ͽͿΆΈ-ΊΌΎ-ΡΣ-ϵϷ-ҁҊ-ԯԱ-Ֆՙա-ևא-תװ-ײؠ-يٮٯٱ-ۓەۥۦۮۯۺ-ۼۿܐܒ-ܯݍ-ޥޱߊ-ߪߴߵߺࠀ-ࠕࠚࠤࠨࡀ-ࡘࢠ-ࢴऄ-हऽॐक़-ॡॱ-ঀঅ-ঌএঐও-নপ-রলশ-হঽৎড়ঢ়য়-ৡৰৱਅ-ਊਏਐਓ-ਨਪ-ਰਲਲ਼ਵਸ਼ਸਹਖ਼-ੜਫ਼ੲ-ੴઅ-ઍએ-ઑઓ-નપ-રલળવ-હઽૐૠૡૹଅ-ଌଏଐଓ-ନପ-ରଲଳଵ-ହଽଡ଼ଢ଼ୟ-ୡୱஃஅ-ஊஎ-ஐஒ-கஙசஜஞடணதந-பம-ஹௐఅ-ఌఎ-ఐఒ-నప-హఽౘ-ౚౠౡಅ-ಌಎ-ಐಒ-ನಪ-ಳವ-ಹಽೞೠೡೱೲഅ-ഌഎ-ഐഒ-ഺഽൎൟ-ൡൺ-ൿඅ-ඖක-නඳ-රලව-ෆก-ะาำเ-ๆກຂຄງຈຊຍດ-ທນ-ຟມ-ຣລວສຫອ-ະາຳຽເ-ໄໆໜ-ໟༀཀ-ཇཉ-ཬྈ-ྌက-ဪဿၐ-ၕၚ-ၝၡၥၦၮ-ၰၵ-ႁႎႠ-ჅჇჍა-ჺჼ-ቈቊ-ቍቐ-ቖቘቚ-ቝበ-ኈኊ-ኍነ-ኰኲ-ኵኸ-ኾዀዂ-ዅወ-ዖዘ-ጐጒ-ጕጘ-ፚᎀ-ᎏᎠ-Ᏽᏸ-ᏽᐁ-ᙬᙯ-ᙿᚁ-ᚚᚠ-ᛪᛱ-ᛸᜀ-ᜌᜎ-ᜑᜠ-ᜱᝀ-ᝑᝠ-ᝬᝮ-ᝰក-ឳៗៜᠠ-ᡷᢀ-ᢨᢪᢰ-ᣵᤀ-ᤞᥐ-ᥭᥰ-ᥴᦀ-ᦫᦰ-ᧉᨀ-ᨖᨠ-ᩔᪧᬅ-ᬳᭅ-ᭋᮃ-ᮠᮮᮯᮺ-ᯥᰀ-ᰣᱍ-ᱏᱚ-ᱽᳩ-ᳬᳮ-ᳱᳵᳶᴀ-ᶿḀ-ἕἘ-Ἕἠ-ὅὈ-Ὅὐ-ὗὙὛὝὟ-ώᾀ-ᾴᾶ-ᾼιῂ-ῄῆ-ῌῐ-ΐῖ-Ίῠ-Ῥῲ-ῴῶ-ῼⁱⁿₐ-ₜℂℇℊ-ℓℕℙ-ℝℤΩℨK-ℭℯ-ℹℼ-ℿⅅ-ⅉⅎↃↄⰀ-Ⱞⰰ-ⱞⱠ-ⳤⳫ-ⳮⳲⳳⴀ-ⴥⴧⴭⴰ-ⵧⵯⶀ-ⶖⶠ-ⶦⶨ-ⶮⶰ-ⶶⶸ-ⶾⷀ-ⷆⷈ-ⷎⷐ-ⷖⷘ-ⷞⸯ々〆〱-〵〻〼ぁ-ゖゝ-ゟァ-ヺー-ヿㄅ-ㄭㄱ-ㆎㆠ-ㆺㇰ-ㇿ㐀-䶵一-鿕ꀀ-ꒌꓐ-ꓽꔀ-ꘌꘐ-ꘟꘪꘫꙀ-ꙮꙿ-ꚝꚠ-ꛥꜗ-ꜟꜢ-ꞈꞋ-ꞭꞰ-ꞷꟷ-ꠁꠃ-ꠅꠇ-ꠊꠌ-ꠢꡀ-ꡳꢂ-ꢳꣲ-ꣷꣻꣽꤊ-ꤥꤰ-ꥆꥠ-ꥼꦄ-ꦲꧏꧠ-ꧤꧦ-ꧯꧺ-ꧾꨀ-ꨨꩀ-ꩂꩄ-ꩋꩠ-ꩶꩺꩾ-ꪯꪱꪵꪶꪹ-ꪽꫀꫂꫛ-ꫝꫠ-ꫪꫲ-ꫴꬁ-ꬆꬉ-ꬎꬑ-ꬖꬠ-ꬦꬨ-ꬮꬰ-ꭚꭜ-ꭥꭰ-ꯢ가-힣ힰ-ퟆퟋ-ퟻ豈-舘並-龎ﬀ-ﬆﬓ-ﬗיִײַ-ﬨשׁ-זּטּ-לּמּנּסּףּפּצּ-ﮱﯓ-ﴽﵐ-ﶏﶒ-ﷇﷰ-ﷻﹰ-ﹴﹶ-ﻼＡ-Ｚａ-ｚｦ-ﾾￂ-ￇￊ-ￏￒ-ￗￚ-ￜ",t="0-9٠-٩۰-۹߀-߉०-९০-৯੦-੯૦-૯୦-୯௦-௯౦-౯೦-೯൦-൯෦-෯๐-๙໐-໙༠-༩၀-၉႐-႙០-៩᠐-᠙᥆-᥏᧐-᧙᪀-᪉᪐-᪙᭐-᭙᮰-᮹᱀-᱉᱐-᱙꘠-꘩꣐-꣙꤀-꤉꧐-꧙꧰-꧹꩐-꩙꯰-꯹０-９",r=e+t,n="(?:["+t+"]{1,3}\\\\.){3}["+t+"]{1,3}",a="["+r+"](?:["+r+"\\\\-]{0,61}["+r+"])?",i=function(e){return"(?=("+a+"))\\\\"+e},s=function(e){return"(?:"+i(e)+"(?:\\\\."+i(e+1)+"){0,126}|"+n+")"};return{alphaNumericCharsStr:r,alphaCharsStr:e,getDomainNameStr:s}}(),e.AnchorTagBuilder=e.Util.extend(Object,{constructor:function(e){e=e||{},this.newWindow=e.newWindow,this.truncate=e.truncate,this.className=e.className},build:function(t){return new e.HtmlTag({tagName:"a",attrs:this.createAttrs(t),innerHtml:this.processAnchorText(t.getAnchorText())})},createAttrs:function(e){var t={href:e.getAnchorHref()},r=this.createCssClass(e);return r&&(t["class"]=r),this.newWindow&&(t.target="_blank",t.rel="noopener noreferrer"),this.truncate&&this.truncate.length&&this.truncate.length<e.getAnchorText().length&&(t.title=e.getAnchorHref()),t},createCssClass:function(e){var t=this.className;if(t){for(var r=[t],n=e.getCssClassSuffixes(),a=0,i=n.length;a<i;a++)r.push(t+"-"+n[a]);return r.join(" ")}return""},processAnchorText:function(e){return e=this.doTruncate(e)},doTruncate:function(t){var r=this.truncate;if(!r||!r.length)return t;var n=r.length,a=r.location;return"smart"===a?e.truncate.TruncateSmart(t,n):"middle"===a?e.truncate.TruncateMiddle(t,n):e.truncate.TruncateEnd(t,n)}}),e.htmlParser.HtmlParser=e.Util.extend(Object,{htmlRegex:function(){var e=/!--([\\s\\S]+?)--/,t=/[0-9a-zA-Z][0-9a-zA-Z:]*/,r=/[^\\s"'>\\/=\\x00-\\x1F\\x7F]+/,n=/(?:"[^"]*?"|'[^']*?'|[^'"=<>`\\s]+)/,a="(?:\\\\s*?=\\\\s*?"+n.source+")?",i=function(e){return"(?=("+r.source+"))\\\\"+e+a};return new RegExp(["(?:","<(!DOCTYPE)","(?:","\\\\s+","(?:",i(2),"|",n.source+")",")*",">",")","|","(?:","<(/)?","(?:",e.source,"|","(?:","("+t.source+")","\\\\s*/?",")","|","(?:","("+t.source+")","\\\\s+","(?:","(?:\\\\s+|\\\\b)",i(7),")*","\\\\s*/?",")",")",">",")"].join(""),"gi")}(),htmlCharacterEntitiesRegex:/(&nbsp;|&#160;|&lt;|&#60;|&gt;|&#62;|&quot;|&#34;|&#39;)/gi,parse:function(e){for(var t,r,n=this.htmlRegex,a=0,i=[];null!==(t=n.exec(e));){var s=t[0],o=t[4],c=t[1]||t[5]||t[6],h=!!t[3],l=t.index,u=e.substring(a,l);u&&(r=this.parseTextAndEntityNodes(a,u),i.push.apply(i,r)),o?i.push(this.createCommentNode(l,s,o)):i.push(this.createElementNode(l,s,c,h)),a=l+s.length}if(a<e.length){var g=e.substring(a);g&&(r=this.parseTextAndEntityNodes(a,g),r.forEach(function(e){i.push(e)}))}return i},parseTextAndEntityNodes:function(t,r){for(var n=[],a=e.Util.splitAndCapture(r,this.htmlCharacterEntitiesRegex),i=0,s=a.length;i<s;i+=2){var o=a[i],c=a[i+1];o&&(n.push(this.createTextNode(t,o)),t+=o.length),c&&(n.push(this.createEntityNode(t,c)),t+=c.length)}return n},createCommentNode:function(t,r,n){return new e.htmlParser.CommentNode({offset:t,text:r,comment:e.Util.trim(n)})},createElementNode:function(t,r,n,a){return new e.htmlParser.ElementNode({offset:t,text:r,tagName:n.toLowerCase(),closing:a})},createEntityNode:function(t,r){return new e.htmlParser.EntityNode({offset:t,text:r})},createTextNode:function(t,r){return new e.htmlParser.TextNode({offset:t,text:r})}}),e.htmlParser.HtmlNode=e.Util.extend(Object,{offset:void 0,text:void 0,constructor:function(t){e.Util.assign(this,t)},getType:e.Util.abstractMethod,getOffset:function(){return this.offset},getText:function(){return this.text}}),e.htmlParser.CommentNode=e.Util.extend(e.htmlParser.HtmlNode,{comment:"",getType:function(){return"comment"},getComment:function(){return this.comment}}),e.htmlParser.ElementNode=e.Util.extend(e.htmlParser.HtmlNode,{tagName:"",closing:!1,getType:function(){return"element"},getTagName:function(){return this.tagName},isClosing:function(){return this.closing}}),e.htmlParser.EntityNode=e.Util.extend(e.htmlParser.HtmlNode,{getType:function(){return"entity"}}),e.htmlParser.TextNode=e.Util.extend(e.htmlParser.HtmlNode,{getType:function(){return"text"}}),e.match.Match=e.Util.extend(Object,{constructor:function(e){this.tagBuilder=e.tagBuilder,this.matchedText=e.matchedText,this.offset=e.offset},getType:e.Util.abstractMethod,getMatchedText:function(){return this.matchedText},setOffset:function(e){this.offset=e},getOffset:function(){return this.offset},getAnchorHref:e.Util.abstractMethod,getAnchorText:e.Util.abstractMethod,getCssClassSuffixes:function(){return[this.getType()]},buildTag:function(){return this.tagBuilder.build(this)}}),e.match.Email=e.Util.extend(e.match.Match,{constructor:function(t){e.match.Match.prototype.constructor.call(this,t),this.email=t.email},getType:function(){return"email"},getEmail:function(){return this.email},getAnchorHref:function(){return"mailto:"+this.email},getAnchorText:function(){return this.email}}),e.match.Hashtag=e.Util.extend(e.match.Match,{constructor:function(t){e.match.Match.prototype.constructor.call(this,t),this.serviceName=t.serviceName,this.hashtag=t.hashtag},getType:function(){return"hashtag"},getServiceName:function(){return this.serviceName},getHashtag:function(){return this.hashtag},getAnchorHref:function(){var e=this.serviceName,t=this.hashtag;switch(e){case"twitter":return"https://twitter.com/hashtag/"+t;case"facebook":return"https://www.facebook.com/hashtag/"+t;case"instagram":return"https://instagram.com/explore/tags/"+t;default:throw new Error("Unknown service name to point hashtag to: ",e)}},getAnchorText:function(){return"#"+this.hashtag}}),e.match.Phone=e.Util.extend(e.match.Match,{constructor:function(t){e.match.Match.prototype.constructor.call(this,t),this.number=t.number,this.plusSign=t.plusSign},getType:function(){return"phone"},getNumber:function(){return this.number},getAnchorHref:function(){return"tel:"+(this.plusSign?"+":"")+this.number},getAnchorText:function(){return this.matchedText}}),e.match.Mention=e.Util.extend(e.match.Match,{constructor:function(t){e.match.Match.prototype.constructor.call(this,t),this.mention=t.mention,this.serviceName=t.serviceName},getType:function(){return"mention"},getMention:function(){return this.mention},getServiceName:function(){return this.serviceName},getAnchorHref:function(){switch(this.serviceName){case"twitter":return"https://twitter.com/"+this.mention;case"instagram":return"https://instagram.com/"+this.mention;default:throw new Error("Unknown service name to point mention to: ",this.serviceName)}},getAnchorText:function(){return"@"+this.mention},getCssClassSuffixes:function(){var t=e.match.Match.prototype.getCssClassSuffixes.call(this),r=this.getServiceName();return r&&t.push(r),t}}),e.match.Url=e.Util.extend(e.match.Match,{constructor:function(t){e.match.Match.prototype.constructor.call(this,t),this.urlMatchType=t.urlMatchType,this.url=t.url,this.protocolUrlMatch=t.protocolUrlMatch,this.protocolRelativeMatch=t.protocolRelativeMatch,this.stripPrefix=t.stripPrefix,this.stripTrailingSlash=t.stripTrailingSlash,this.decodePercentEncoding=t.decodePercentEncoding},schemePrefixRegex:/^(https?:\\/\\/)?/i,wwwPrefixRegex:/^(https?:\\/\\/)?(www\\.)?/i,protocolRelativeRegex:/^\\/\\//,protocolPrepended:!1,getType:function(){return"url"},getUrlMatchType:function(){return this.urlMatchType},getUrl:function(){var e=this.url;return this.protocolRelativeMatch||this.protocolUrlMatch||this.protocolPrepended||(e=this.url="http://"+e,this.protocolPrepended=!0),e},getAnchorHref:function(){var e=this.getUrl();return e.replace(/&amp;/g,"&")},getAnchorText:function(){var e=this.getMatchedText();return this.protocolRelativeMatch&&(e=this.stripProtocolRelativePrefix(e)),this.stripPrefix.scheme&&(e=this.stripSchemePrefix(e)),this.stripPrefix.www&&(e=this.stripWwwPrefix(e)),this.stripTrailingSlash&&(e=this.removeTrailingSlash(e)),this.decodePercentEncoding&&(e=this.removePercentEncoding(e)),e},stripSchemePrefix:function(e){return e.replace(this.schemePrefixRegex,"")},stripWwwPrefix:function(e){return e.replace(this.wwwPrefixRegex,"$1")},stripProtocolRelativePrefix:function(e){return e.replace(this.protocolRelativeRegex,"")},removeTrailingSlash:function(e){return"/"===e.charAt(e.length-1)&&(e=e.slice(0,-1)),e},removePercentEncoding:function(e){try{return decodeURIComponent(e.replace(/%22/gi,"&quot;").replace(/%26/gi,"&amp;").replace(/%27/gi,"&#39;").replace(/%3C/gi,"&lt;").replace(/%3E/gi,"&gt;"))}catch(t){return e}}}),e.tldRegex=/(?:xn--vermgensberatung-pwb|xn--vermgensberater-ctb|xn--clchc0ea0b2g2a9gcd|xn--w4r85el8fhu5dnra|northwesternmutual|travelersinsurance|vermögensberatung|xn--3oq18vl8pn36a|xn--5su34j936bgsg|xn--bck1b9a5dre4c|xn--mgbai9azgqp6j|xn--mgberp4a5d4ar|xn--xkc2dl3a5ee0h|vermögensberater|xn--fzys8d69uvgm|xn--mgba7c0bbn0a|xn--xkc2al3hye2a|americanexpress|kerryproperties|sandvikcoromant|xn--i1b6b1a6a2e|xn--kcrx77d1x4a|xn--lgbbat1ad8j|xn--mgba3a4f16a|xn--mgbc0a9azcg|xn--nqv7fs00ema|afamilycompany|americanfamily|bananarepublic|cancerresearch|cookingchannel|kerrylogistics|weatherchannel|xn--54b7fta0cc|xn--6qq986b3xl|xn--80aqecdr1a|xn--b4w605ferd|xn--fiq228c5hs|xn--jlq61u9w7b|xn--mgba3a3ejt|xn--mgbaam7a8h|xn--mgbayh7gpa|xn--mgbb9fbpob|xn--mgbbh1a71e|xn--mgbca7dzdo|xn--mgbi4ecexp|xn--mgbx4cd0ab|international|lifeinsurance|orientexpress|spreadbetting|travelchannel|wolterskluwer|xn--eckvdtc9d|xn--fpcrj9c3d|xn--fzc2c9e2c|xn--tiq49xqyj|xn--yfro4i67o|xn--ygbi2ammx|construction|lplfinancial|pamperedchef|scholarships|versicherung|xn--3e0b707e|xn--80adxhks|xn--80asehdb|xn--8y0a063a|xn--gckr3f0f|xn--mgb9awbf|xn--mgbab2bd|xn--mgbpl2fh|xn--mgbt3dhd|xn--mk1bu44c|xn--ngbc5azd|xn--ngbe9e0a|xn--ogbpf8fl|xn--qcka1pmc|accountants|barclaycard|blackfriday|blockbuster|bridgestone|calvinklein|contractors|creditunion|engineering|enterprises|foodnetwork|investments|kerryhotels|lamborghini|motorcycles|olayangroup|photography|playstation|productions|progressive|redumbrella|rightathome|williamhill|xn--11b4c3d|xn--1ck2e1b|xn--1qqw23a|xn--3bst00m|xn--3ds443g|xn--42c2d9a|xn--45brj9c|xn--55qw42g|xn--6frz82g|xn--80ao21a|xn--9krt00a|xn--cck2b3b|xn--czr694b|xn--d1acj3b|xn--efvy88h|xn--estv75g|xn--fct429k|xn--fjq720a|xn--flw351e|xn--g2xx48c|xn--gecrj9c|xn--gk3at1e|xn--h2brj9c|xn--hxt814e|xn--imr513n|xn--j6w193g|xn--jvr189m|xn--kprw13d|xn--kpry57d|xn--kpu716f|xn--mgbtx2b|xn--mix891f|xn--nyqy26a|xn--pbt977c|xn--pgbs0dh|xn--q9jyb4c|xn--rhqv96g|xn--rovu88b|xn--s9brj9c|xn--ses554g|xn--t60b56a|xn--vuq861b|xn--w4rs40l|xn--xhq521b|xn--zfr164b|சிங்கப்பூர்|accountant|apartments|associates|basketball|bnpparibas|boehringer|capitalone|consulting|creditcard|cuisinella|eurovision|extraspace|foundation|healthcare|immobilien|industries|management|mitsubishi|nationwide|newholland|nextdirect|onyourside|properties|protection|prudential|realestate|republican|restaurant|schaeffler|swiftcover|tatamotors|technology|telefonica|university|vistaprint|vlaanderen|volkswagen|xn--30rr7y|xn--3pxu8k|xn--45q11c|xn--4gbrim|xn--55qx5d|xn--5tzm5g|xn--80aswg|xn--90a3ac|xn--9dbq2a|xn--9et52u|xn--c2br7g|xn--cg4bki|xn--czrs0t|xn--czru2d|xn--fiq64b|xn--fiqs8s|xn--fiqz9s|xn--io0a7i|xn--kput3i|xn--mxtq1m|xn--o3cw4h|xn--pssy2u|xn--unup4y|xn--wgbh1c|xn--wgbl6a|xn--y9a3aq|accenture|alfaromeo|allfinanz|amsterdam|analytics|aquarelle|barcelona|bloomberg|christmas|community|directory|education|equipment|fairwinds|financial|firestone|fresenius|frontdoor|fujixerox|furniture|goldpoint|goodhands|hisamitsu|homedepot|homegoods|homesense|honeywell|institute|insurance|kuokgroup|ladbrokes|lancaster|landrover|lifestyle|marketing|marshalls|mcdonalds|melbourne|microsoft|montblanc|panasonic|passagens|pramerica|richardli|scjohnson|shangrila|solutions|statebank|statefarm|stockholm|travelers|vacations|xn--90ais|xn--c1avg|xn--d1alf|xn--e1a4c|xn--fhbei|xn--j1aef|xn--j1amh|xn--l1acc|xn--nqv7f|xn--p1acf|xn--tckwe|xn--vhquv|yodobashi|abudhabi|airforce|allstate|attorney|barclays|barefoot|bargains|baseball|boutique|bradesco|broadway|brussels|budapest|builders|business|capetown|catering|catholic|chrysler|cipriani|cityeats|cleaning|clinique|clothing|commbank|computer|delivery|deloitte|democrat|diamonds|discount|discover|download|engineer|ericsson|esurance|everbank|exchange|feedback|fidelity|firmdale|football|frontier|goodyear|grainger|graphics|guardian|hdfcbank|helsinki|holdings|hospital|infiniti|ipiranga|istanbul|jpmorgan|lighting|lundbeck|marriott|maserati|mckinsey|memorial|mortgage|movistar|observer|partners|pharmacy|pictures|plumbing|property|redstone|reliance|saarland|samsclub|security|services|shopping|showtime|softbank|software|stcgroup|supplies|symantec|telecity|training|uconnect|vanguard|ventures|verisign|woodside|xn--90ae|xn--node|xn--p1ai|xn--qxam|yokohama|السعودية|abogado|academy|agakhan|alibaba|android|athleta|auction|audible|auspost|avianca|banamex|bauhaus|bentley|bestbuy|booking|brother|bugatti|capital|caravan|careers|cartier|channel|chintai|citadel|clubmed|college|cologne|comcast|company|compare|contact|cooking|corsica|country|coupons|courses|cricket|cruises|dentist|digital|domains|exposed|express|farmers|fashion|ferrari|ferrero|finance|fishing|fitness|flights|florist|flowers|forsale|frogans|fujitsu|gallery|genting|godaddy|guitars|hamburg|hangout|hitachi|holiday|hosting|hoteles|hotmail|hyundai|iselect|ismaili|jewelry|juniper|kitchen|komatsu|lacaixa|lancome|lanxess|lasalle|latrobe|leclerc|liaison|limited|lincoln|markets|metlife|monster|netbank|netflix|network|neustar|okinawa|oldnavy|organic|origins|panerai|philips|pioneer|politie|realtor|recipes|rentals|reviews|rexroth|samsung|sandvik|schmidt|schwarz|science|shiksha|shriram|singles|spiegel|staples|starhub|statoil|storage|support|surgery|systems|temasek|theater|theatre|tickets|tiffany|toshiba|trading|walmart|wanggou|watches|weather|website|wedding|whoswho|windows|winners|xfinity|yamaxun|youtube|zuerich|католик|الجزائر|العليان|پاکستان|كاثوليك|موبايلي|இந்தியா|abarth|abbott|abbvie|active|africa|agency|airbus|airtel|alipay|alsace|alstom|anquan|aramco|author|bayern|beauty|berlin|bharti|blanco|bostik|boston|broker|camera|career|caseih|casino|center|chanel|chrome|church|circle|claims|clinic|coffee|comsec|condos|coupon|credit|cruise|dating|datsun|dealer|degree|dental|design|direct|doctor|dunlop|dupont|durban|emerck|energy|estate|events|expert|family|flickr|futbol|gallup|garden|george|giving|global|google|gratis|health|hermes|hiphop|hockey|hughes|imamat|insure|intuit|jaguar|joburg|juegos|kaufen|kinder|kindle|kosher|lancia|latino|lawyer|lefrak|living|locker|london|luxury|madrid|maison|makeup|market|mattel|mobile|mobily|monash|mormon|moscow|museum|mutual|nagoya|natura|nissan|nissay|norton|nowruz|office|olayan|online|oracle|orange|otsuka|pfizer|photos|physio|piaget|pictet|quebec|racing|realty|reisen|repair|report|review|rocher|rogers|ryukyu|safety|sakura|sanofi|school|schule|secure|select|shouji|soccer|social|stream|studio|supply|suzuki|swatch|sydney|taipei|taobao|target|tattoo|tennis|tienda|tjmaxx|tkmaxx|toyota|travel|unicom|viajes|viking|villas|virgin|vision|voting|voyage|vuelos|walter|warman|webcam|xihuan|xperia|yachts|yandex|zappos|москва|онлайн|ابوظبي|ارامكو|الاردن|المغرب|امارات|فلسطين|مليسيا|இலங்கை|ファッション|actor|adult|aetna|amfam|amica|apple|archi|audio|autos|azure|baidu|beats|bible|bingo|black|boats|boots|bosch|build|canon|cards|chase|cheap|chloe|cisco|citic|click|cloud|coach|codes|crown|cymru|dabur|dance|deals|delta|dodge|drive|dubai|earth|edeka|email|epost|epson|faith|fedex|final|forex|forum|gallo|games|gifts|gives|glade|glass|globo|gmail|green|gripe|group|gucci|guide|homes|honda|horse|house|hyatt|ikano|intel|irish|iveco|jetzt|koeln|kyoto|lamer|lease|legal|lexus|lilly|linde|lipsy|lixil|loans|locus|lotte|lotto|lupin|macys|mango|media|miami|money|mopar|movie|nadex|nexus|nikon|ninja|nokia|nowtv|omega|osaka|paris|parts|party|phone|photo|pizza|place|poker|praxi|press|prime|promo|quest|radio|rehab|reise|ricoh|rocks|rodeo|salon|sener|seven|sharp|shell|shoes|skype|sling|smart|smile|solar|space|stada|store|study|style|sucks|swiss|tatar|tires|tirol|tmall|today|tokyo|tools|toray|total|tours|trade|trust|tunes|tushu|ubank|vegas|video|vista|vodka|volvo|wales|watch|weber|weibo|works|world|xerox|yahoo|zippo|ایران|بازار|بھارت|سودان|سورية|همراه|संगठन|বাংলা|భారత్|嘉里大酒店|aarp|able|adac|aero|aigo|akdn|ally|amex|army|arpa|arte|asda|asia|audi|auto|baby|band|bank|bbva|beer|best|bike|bing|blog|blue|bofa|bond|book|buzz|cafe|call|camp|care|cars|casa|case|cash|cbre|cern|chat|citi|city|club|cool|coop|cyou|data|date|dclk|deal|dell|desi|diet|dish|docs|doha|duck|duns|dvag|erni|fage|fail|fans|farm|fast|fiat|fido|film|fire|fish|flir|food|ford|free|fund|game|gbiz|gent|ggee|gift|gmbh|gold|golf|goog|guge|guru|hair|haus|hdfc|help|here|hgtv|host|hsbc|icbc|ieee|imdb|immo|info|itau|java|jeep|jobs|jprs|kddi|kiwi|kpmg|kred|land|lego|lgbt|lidl|life|like|limo|link|live|loan|loft|love|ltda|luxe|maif|meet|meme|menu|mini|mint|mobi|moda|moto|mtpc|name|navy|news|next|nico|nike|ollo|open|page|pars|pccw|pics|ping|pink|play|plus|pohl|porn|post|prod|prof|qpon|raid|read|reit|rent|rest|rich|rmit|room|rsvp|ruhr|safe|sale|sapo|sarl|save|saxo|scor|scot|seat|seek|sexy|shaw|shia|shop|show|silk|sina|site|skin|sncf|sohu|song|sony|spot|star|surf|talk|taxi|team|tech|teva|tiaa|tips|town|toys|tube|vana|visa|viva|vivo|vote|voto|wang|weir|wien|wiki|wine|work|xbox|yoga|zara|zero|zone|дети|сайт|بيتك|تونس|شبكة|عراق|عمان|موقع|भारत|ভারত|ਭਾਰਤ|ભારત|ලංකා|グーグル|クラウド|ポイント|大众汽车|组织机构|電訊盈科|香格里拉|aaa|abb|abc|aco|ads|aeg|afl|aig|anz|aol|app|art|aws|axa|bar|bbc|bbt|bcg|bcn|bet|bid|bio|biz|bms|bmw|bnl|bom|boo|bot|box|buy|bzh|cab|cal|cam|car|cat|cba|cbn|cbs|ceb|ceo|cfa|cfd|com|crs|csc|dad|day|dds|dev|dhl|diy|dnp|dog|dot|dtv|dvr|eat|eco|edu|esq|eus|fan|fit|fly|foo|fox|frl|ftr|fun|fyi|gal|gap|gdn|gea|gle|gmo|gmx|goo|gop|got|gov|hbo|hiv|hkt|hot|how|htc|ibm|ice|icu|ifm|ing|ink|int|ist|itv|iwc|jcb|jcp|jio|jlc|jll|jmp|jnj|jot|joy|kfh|kia|kim|kpn|krd|lat|law|lds|lol|lpl|ltd|man|mba|mcd|med|men|meo|mil|mit|mlb|mls|mma|moe|moi|mom|mov|msd|mtn|mtr|nab|nba|nec|net|new|nfl|ngo|nhk|now|nra|nrw|ntt|nyc|obi|off|one|ong|onl|ooo|org|ott|ovh|pay|pet|pid|pin|pnc|pro|pru|pub|pwc|qvc|red|ren|ril|rio|rip|run|rwe|sap|sas|sbi|sbs|sca|scb|ses|sew|sex|sfr|ski|sky|soy|srl|srt|stc|tab|tax|tci|tdk|tel|thd|tjx|top|trv|tui|tvs|ubs|uno|uol|ups|vet|vig|vin|vip|wed|win|wme|wow|wtc|wtf|xin|xxx|xyz|you|yun|zip|бел|ком|қаз|мкд|мон|орг|рус|срб|укр|հայ|קום|قطر|كوم|مصر|कॉम|नेट|คอม|ไทย|ストア|セール|みんな|中文网|天主教|我爱你|新加坡|淡马锡|诺基亚|飞利浦|ac|ad|ae|af|ag|ai|al|am|ao|aq|ar|as|at|au|aw|ax|az|ba|bb|bd|be|bf|bg|bh|bi|bj|bm|bn|bo|br|bs|bt|bv|bw|by|bz|ca|cc|cd|cf|cg|ch|ci|ck|cl|cm|cn|co|cr|cu|cv|cw|cx|cy|cz|de|dj|dk|dm|do|dz|ec|ee|eg|er|es|et|eu|fi|fj|fk|fm|fo|fr|ga|gb|gd|ge|gf|gg|gh|gi|gl|gm|gn|gp|gq|gr|gs|gt|gu|gw|gy|hk|hm|hn|hr|ht|hu|id|ie|il|im|in|io|iq|ir|is|it|je|jm|jo|jp|ke|kg|kh|ki|km|kn|kp|kr|kw|ky|kz|la|lb|lc|li|lk|lr|ls|lt|lu|lv|ly|ma|mc|md|me|mg|mh|mk|ml|mm|mn|mo|mp|mq|mr|ms|mt|mu|mv|mw|mx|my|mz|na|nc|ne|nf|ng|ni|nl|no|np|nr|nu|nz|om|pa|pe|pf|pg|ph|pk|pl|pm|pn|pr|ps|pt|pw|py|qa|re|ro|rs|ru|rw|sa|sb|sc|sd|se|sg|sh|si|sj|sk|sl|sm|sn|so|sr|st|su|sv|sx|sy|sz|tc|td|tf|tg|th|tj|tk|tl|tm|tn|to|tr|tt|tv|tw|tz|ua|ug|uk|us|uy|uz|va|vc|ve|vg|vi|vn|vu|wf|ws|ye|yt|za|zm|zw|ελ|бг|ею|рф|გე|닷넷|닷컴|삼성|한국|コム|世界|中信|中国|中國|企业|佛山|信息|健康|八卦|公司|公益|台湾|台灣|商城|商店|商标|嘉里|在线|大拿|娱乐|家電|工行|广东|微博|慈善|手机|手表|政务|政府|新闻|时尚|書籍|机构|游戏|澳門|点看|珠宝|移动|网址|网店|网站|网络|联通|谷歌|购物|通販|集团|食品|餐厅|香港)/,e.matcher.Matcher=e.Util.extend(Object,{constructor:function(e){this.tagBuilder=e.tagBuilder},parseMatches:e.Util.abstractMethod}),e.matcher.Email=e.Util.extend(e.matcher.Matcher,{matcherRegex:function(){var t=e.RegexLib.alphaNumericCharsStr,r="!#$%&'*+\\\\-\\\\/=?^_`{|}~",n='\\\\s"(),:;<>@\\\\[\\\\]',a=t+r,i=a+n,s=new RegExp("(?:["+a+"](?:["+a+']|\\\\.(?!\\\\.|@))*|\\\\"['+i+'.]+\\\\")@'),o=e.RegexLib.getDomainNameStr,c=e.tldRegex;return new RegExp([s.source,o(1),"\\\\.",c.source].join(""),"gi")}(),parseMatches:function(t){for(var r,n=this.matcherRegex,a=this.tagBuilder,i=[];null!==(r=n.exec(t));){var s=r[0];i.push(new e.match.Email({tagBuilder:a,matchedText:s,offset:r.index,email:s}))}return i}}),e.matcher.Hashtag=e.Util.extend(e.matcher.Matcher,{matcherRegex:new RegExp("#[_"+e.RegexLib.alphaNumericCharsStr+"]{1,139}","g"),nonWordCharRegex:new RegExp("[^"+e.RegexLib.alphaNumericCharsStr+"]"),constructor:function(t){e.matcher.Matcher.prototype.constructor.call(this,t),this.serviceName=t.serviceName},parseMatches:function(t){for(var r,n=this.matcherRegex,a=this.nonWordCharRegex,i=this.serviceName,s=this.tagBuilder,o=[];null!==(r=n.exec(t));){var c=r.index,h=t.charAt(c-1);if(0===c||a.test(h)){var l=r[0],u=r[0].slice(1);o.push(new e.match.Hashtag({tagBuilder:s,matchedText:l,offset:c,serviceName:i,hashtag:u}))}}return o}}),e.matcher.Phone=e.Util.extend(e.matcher.Matcher,{matcherRegex:/(?:(?:(?:(\\+)?\\d{1,3}[-\\040.]?)?\\(?\\d{3}\\)?[-\\040.]?\\d{3}[-\\040.]?\\d{4})|(?:(\\+)(?:9[976]\\d|8[987530]\\d|6[987]\\d|5[90]\\d|42\\d|3[875]\\d|2[98654321]\\d|9[8543210]|8[6421]|6[6543210]|5[87654321]|4[987654310]|3[9643210]|2[70]|7|1)[-\\040.]?(?:\\d[-\\040.]?){6,12}\\d+))([,;]+[0-9]+#?)*/g,parseMatches:function(t){for(var r,n=this.matcherRegex,a=this.tagBuilder,i=[];null!==(r=n.exec(t));){var s=r[0],o=s.replace(/[^0-9,;#]/g,""),c=!(!r[1]&&!r[2]),h=0==r.index?"":t.substr(r.index-1,1),l=t.substr(r.index+s.length,1),u=!h.match(/\\d/)&&!l.match(/\\d/);this.testMatch(r[3])&&this.testMatch(s)&&u&&i.push(new e.match.Phone({tagBuilder:a,matchedText:s,offset:r.index,number:o,plusSign:c}))}return i},testMatch:function(e){return/\\D/.test(e)}}),e.matcher.Mention=e.Util.extend(e.matcher.Matcher,{matcherRegexes:{twitter:new RegExp("@[_"+e.RegexLib.alphaNumericCharsStr+"]{1,20}","g"),instagram:new RegExp("@[_."+e.RegexLib.alphaNumericCharsStr+"]{1,50}","g")},nonWordCharRegex:new RegExp("[^"+e.RegexLib.alphaNumericCharsStr+"]"),constructor:function(t){e.matcher.Matcher.prototype.constructor.call(this,t),this.serviceName=t.serviceName},parseMatches:function(t){var r,n=this.matcherRegexes[this.serviceName],a=this.nonWordCharRegex,i=this.serviceName,s=this.tagBuilder,o=[];if(!n)return o;for(;null!==(r=n.exec(t));){var c=r.index,h=t.charAt(c-1);if(0===c||a.test(h)){var l=r[0].replace(/\\.+$/g,""),u=l.slice(1);o.push(new e.match.Mention({tagBuilder:s,matchedText:l,offset:c,serviceName:i,mention:u}))}}return o}}),e.matcher.Url=e.Util.extend(e.matcher.Matcher,{matcherRegex:function(){var t=/(?:[A-Za-z][-.+A-Za-z0-9]{0,63}:(?![A-Za-z][-.+A-Za-z0-9]{0,63}:\\/\\/)(?!\\d+\\/?)(?:\\/\\/)?)/,r=/(?:www\\.)/,n=e.RegexLib.getDomainNameStr,a=e.tldRegex,i=e.RegexLib.alphaNumericCharsStr,s=new RegExp("[/?#](?:["+i+"\\\\-+&@#/%=~_()|'$*\\\\[\\\\]?!:,.;✓]*["+i+"\\\\-+&@#/%=~_()|'$*\\\\[\\\\]✓])?");return new RegExp(["(?:","(",t.source,n(2),")","|","(","(//)?",r.source,n(6),")","|","(","(//)?",n(10)+"\\\\.",a.source,"(?![-"+i+"])",")",")","(?::[0-9]+)?","(?:"+s.source+")?"].join(""),"gi")}(),wordCharRegExp:new RegExp("["+e.RegexLib.alphaNumericCharsStr+"]"),openParensRe:/\\(/g,closeParensRe:/\\)/g,constructor:function(t){e.matcher.Matcher.prototype.constructor.call(this,t),this.stripPrefix=t.stripPrefix,this.stripTrailingSlash=t.stripTrailingSlash,this.decodePercentEncoding=t.decodePercentEncoding},parseMatches:function(t){for(var r,n=this.matcherRegex,a=this.stripPrefix,i=this.stripTrailingSlash,s=this.decodePercentEncoding,o=this.tagBuilder,c=[];null!==(r=n.exec(t));){var h=r[0],l=r[1],u=r[4],g=r[5],m=r[9],f=r.index,d=g||m,p=t.charAt(f-1);if(e.matcher.UrlMatchValidator.isValid(h,l)&&!(f>0&&"@"===p||f>0&&d&&this.wordCharRegExp.test(p))){if(/\\?$/.test(h)&&(h=h.substr(0,h.length-1)),this.matchHasUnbalancedClosingParen(h))h=h.substr(0,h.length-1);else{var x=this.matchHasInvalidCharAfterTld(h,l);x>-1&&(h=h.substr(0,x))}var b=l?"scheme":u?"www":"tld",v=!!l;c.push(new e.match.Url({tagBuilder:o,matchedText:h,offset:f,urlMatchType:b,url:h,protocolUrlMatch:v,protocolRelativeMatch:!!d,stripPrefix:a,stripTrailingSlash:i,decodePercentEncoding:s}))}}return c},matchHasUnbalancedClosingParen:function(e){var t=e.charAt(e.length-1);if(")"===t){var r=e.match(this.openParensRe),n=e.match(this.closeParensRe),a=r&&r.length||0,i=n&&n.length||0;
    if(a<i)return!0}return!1},matchHasInvalidCharAfterTld:function(t,r){if(!t)return-1;var n=0;r&&(n=t.indexOf(":"),t=t.slice(n));var a=e.RegexLib.alphaNumericCharsStr,i=new RegExp("^((.?//)?[-."+a+"]*[-"+a+"]\\\\.[-"+a+"]+)"),s=i.exec(t);return null===s?-1:(n+=s[1].length,t=t.slice(s[1].length),/^[^-.A-Za-z0-9:\\/?#]/.test(t)?n:-1)}}),e.matcher.UrlMatchValidator={hasFullProtocolRegex:/^[A-Za-z][-.+A-Za-z0-9]*:\\/\\//,uriSchemeRegex:/^[A-Za-z][-.+A-Za-z0-9]*:/,hasWordCharAfterProtocolRegex:new RegExp(":[^\\\\s]*?["+e.RegexLib.alphaCharsStr+"]"),ipRegex:/[0-9][0-9]?[0-9]?\\.[0-9][0-9]?[0-9]?\\.[0-9][0-9]?[0-9]?\\.[0-9][0-9]?[0-9]?(:[0-9]*)?\\/?$/,isValid:function(e,t){return!(t&&!this.isValidUriScheme(t)||this.urlMatchDoesNotHaveProtocolOrDot(e,t)||this.urlMatchDoesNotHaveAtLeastOneWordChar(e,t)&&!this.isValidIpAddress(e)||this.containsMultipleDots(e))},isValidIpAddress:function(e){var t=new RegExp(this.hasFullProtocolRegex.source+this.ipRegex.source),r=e.match(t);return null!==r},containsMultipleDots:function(e){var t=e;return this.hasFullProtocolRegex.test(e)&&(t=e.split("://")[1]),t.split("/")[0].indexOf("..")>-1},isValidUriScheme:function(e){var t=e.match(this.uriSchemeRegex)[0].toLowerCase();return"javascript:"!==t&&"vbscript:"!==t},urlMatchDoesNotHaveProtocolOrDot:function(e,t){return!(!e||t&&this.hasFullProtocolRegex.test(t)||e.indexOf(".")!==-1)},urlMatchDoesNotHaveAtLeastOneWordChar:function(e,t){return!(!e||!t)&&!this.hasWordCharAfterProtocolRegex.test(e)}},e.truncate.TruncateEnd=function(t,r,n){return e.Util.ellipsis(t,r,n)},e.truncate.TruncateMiddle=function(e,t,r){if(e.length<=t)return e;var n,a;null==r?(r="&hellip;",n=8,a=3):(n=r.length,a=r.length);var i=t-a,s="";return i>0&&(s=e.substr(-1*Math.floor(i/2))),(e.substr(0,Math.ceil(i/2))+r+s).substr(0,i+n)},e.truncate.TruncateSmart=function(e,t,r){var n,a;null==r?(r="&hellip;",a=3,n=8):(a=r.length,n=r.length);var i=function(e){var t={},r=e,n=r.match(/^([a-z]+):\\/\\//i);return n&&(t.scheme=n[1],r=r.substr(n[0].length)),n=r.match(/^(.*?)(?=(\\?|#|\\/|$))/i),n&&(t.host=n[1],r=r.substr(n[0].length)),n=r.match(/^\\/(.*?)(?=(\\?|#|$))/i),n&&(t.path=n[1],r=r.substr(n[0].length)),n=r.match(/^\\?(.*?)(?=(#|$))/i),n&&(t.query=n[1],r=r.substr(n[0].length)),n=r.match(/^#(.*?)$/i),n&&(t.fragment=n[1]),t},s=function(e){var t="";return e.scheme&&e.host&&(t+=e.scheme+"://"),e.host&&(t+=e.host),e.path&&(t+="/"+e.path),e.query&&(t+="?"+e.query),e.fragment&&(t+="#"+e.fragment),t},o=function(e,t){var n=t/2,a=Math.ceil(n),i=-1*Math.floor(n),s="";return i<0&&(s=e.substr(i)),e.substr(0,a)+r+s};if(e.length<=t)return e;var c=t-a,h=i(e);if(h.query){var l=h.query.match(/^(.*?)(?=(\\?|\\#))(.*?)$/i);l&&(h.query=h.query.substr(0,l[1].length),e=s(h))}if(e.length<=t)return e;if(h.host&&(h.host=h.host.replace(/^www\\./,""),e=s(h)),e.length<=t)return e;var u="";if(h.host&&(u+=h.host),u.length>=c)return h.host.length==t?(h.host.substr(0,t-a)+r).substr(0,c+n):o(u,c).substr(0,c+n);var g="";if(h.path&&(g+="/"+h.path),h.query&&(g+="?"+h.query),g){if((u+g).length>=c){if((u+g).length==t)return(u+g).substr(0,t);var m=c-u.length;return(u+o(g,m)).substr(0,c+n)}u+=g}if(h.fragment){var f="#"+h.fragment;if((u+f).length>=c){if((u+f).length==t)return(u+f).substr(0,t);var d=c-u.length;return(u+o(f,d)).substr(0,c+n)}u+=f}if(h.scheme&&h.host){var p=h.scheme+"://";if((u+p).length<c)return(p+u).substr(0,t)}if(u.length<=t)return u;var x="";return c>0&&(x=u.substr(-1*Math.floor(c/2))),(u.substr(0,Math.ceil(c/2))+r+x).substr(0,c+n)},e});
END;
}