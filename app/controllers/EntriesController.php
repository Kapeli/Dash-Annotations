<?php

use \Michelf\MarkdownExtra;
use \HTMLPurifier;
use \HTMLPurifier_Config;

function in_arrayi($needle, $haystack)
{
    return in_array(strtolower($needle), array_map('strtolower', $haystack));
}

class EntriesController extends BaseController {
    public function list_entries()
    {
        $minimum_public_score = -5;
        $identifier_dict = Input::get('identifier');
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
            return $response;
        }
        return json_encode(["status" => "success"]);
    }

    public function save()
    {
        if(Auth::check())
        {
            $title = Input::get('title');
            $body = Input::get('body');
            $public = Input::get('public');
            $type = Input::get('type');
            $teams = Input::get('teams');
            $license = Input::get('license');
            $identifier_dict = Input::get('identifier');
            $anchor = Input::get('anchor');
            $entry_id = Input::get('entry_id');
            $user = Auth::user();

            if(!empty($title) && !empty($body) && !empty($type) && !empty($identifier_dict) && !empty($anchor))
            {
                $db_license = NULL;
                if($public)
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
                            return json_encode(['status' => 'error', 'message' => 'You are banned from making public annotations']);
                        }
                    }
                    else
                    {
                        if(isset($license['is_beta']) && $license['is_beta'])
                        {
                            // skip check for beta users
                        }
                        else if(isset($license['is_app_store']) && $license['is_app_store'])
                        {
                            if(!DashLicenseUtil::check_itunes_receipt($license))
                            {
                                return json_encode(['status' => 'error', 'message' => 'Error. Couldn\'t verify your license']);
                            }
                        }
                        else
                        {
                            if(!DashLicenseUtil::check_license($license))
                            {
                                return json_encode(['status' => 'error', 'message' => 'Error. Couldn\'t verify your license']);
                            }
                        }

                        $db_license = new License;
                        $db_license->license = $json_license;
                        $db_license->save();
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

                $config = HTMLPurifier_Config::createDefault();
                $body = MarkdownExtra::defaultTransform($body);
                $purifier = new HTMLPurifier($config);
                $body = $purifier->purify($body);
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
            $entry_id = Input::get('entry_id');
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
                $entry_id = Input::get('entry_id');
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
            $entry_id = Input::get('entry_id');
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
            $entry_id = Input::get('entry_id');
            $vote_type = Input::get('vote_type');
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
        $entry_id = Input::get('entry_id');
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
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap.min.css">
                    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/css/bootstrap-theme.min.css">
                    <style>
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
                        }

                        .vote {
                            float:left;
                            width:48px;
                            text-align: center;
                            height:64px;
                            margin-left:-10px;
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
                        <div class="arrow-up glyphicon glyphicon-arrow-up '.$voted_up.'" onclick="window.dash.vote_(this);"></div>
                        <div class="score">'.$score.'</div>
                        <div class="arrow-down glyphicon glyphicon-arrow-down '.$voted_down.'" onclick="window.dash.vote_(this);"></div>
                    </div>';            
            }
            $body .= '
                    <div><h2 class="title">'.$entry->title.'</h2>
                    <p class="description"><small>';

            $body .= ($entry->public && !($entry->removed_from_public && $global_moderator)) ? "Public annotation " : @"Private annotation ";
            $body .= 'by <u>'.$entry_user->username.'</u>';
            $team_string = "";
            $i = 0;
            foreach($my_teams as $team)
            {
                ++$i;
                if(strlen($team_string))
                {
                    $team_string .= (count($my_teams) == $i) ? " and " : ", ";
                }
                $team_string .= '<u>'.$team['name'].'</u>';
            }
            if(strlen($team_string))
            {
                $body .= ' in '.$team_string.'';
            }
            $body .= ' ';
            if($user && $user->id == $entry->user_id)
            {
                $body .= '&nbsp;<kbd class="actions edit" onclick="window.dash.edit();">Edit</kbd></span>';
                $body .= '&nbsp;<kbd class="actions delete" onclick="window.dash.delete();">Delete</kbd></span>';
            }
            else
            {
                if($global_moderator && $entry->public && !$entry->removed_from_public)
                {
                    $body .= '&nbsp;<kbd class="actions delete" onclick="window.dash.removeFromPublic();">Remove From Public</kbd></span>';
                }
                if($team_moderator)
                {
                    $body .= '&nbsp;<kbd class="actions delete" onclick="window.dash.removeFromTeams();">Remove From Team';
                    if(count($my_teams) > 1)
                    {
                        $body .= 's';
                    }
                    $body .= '</kbd></span>';
                }
            }
            $body .= '</small><p></div>
                    <p>'.$body_rendered.'</P>
                    </div>
                    <script src="https://code.jquery.com/jquery-1.11.2.min.js"></script>
                    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.2/js/bootstrap.min.js"></script>
                </body>
            </html>';
            return ["status" => "success", "body" => $entry->body, "body_rendered" => $body, "teams" => $my_teams, "global_moderator" => $global_moderator];
        }
        return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
    }
}
