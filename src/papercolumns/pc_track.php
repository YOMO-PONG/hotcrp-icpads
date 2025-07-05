<?php
// pc_track.php -- HotCRP helper class for paper list track content
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Track_PaperColumn extends PaperColumn {
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
    }
    
    function prepare(PaperList $pl, $visible) {
        if (!$pl->conf->has_tracks()) {
            return false;
        }
        $this->className = "pl_track";
        return true;
    }
    
    function completion_name() {
        return "track";
    }
    
    function sort_name() {
        return "track";
    }
    
    function prepare_sort(PaperList $pl, $sortindex) {
        // Track sorting is handled by the track tag
        return true;
    }
    
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $at = $a->track_tag();
        $bt = $b->track_tag();
        if ($at === $bt) {
            return 0;
        }
        if ($at === null) {
            return 1;
        }
        if ($bt === null) {
            return -1;
        }
        return strcmp($at, $bt);
    }
    
    function header(PaperList $pl, $is_text) {
        return "Track";
    }
    
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !$pl->user->can_view_track($row);
    }
    
    function content(PaperList $pl, PaperInfo $row) {
        if (!$pl->user->can_view_track($row)) {
            return "";
        }
        
        $track = $row->track_tag();
        if ($track === null || $track === "") {
            return "none";
        }
        
        // Get track display name
        $track_obj = $pl->conf->track_by_tag($track);
        if ($track_obj) {
            return htmlspecialchars($track_obj->name);
        }
        
        return htmlspecialchars($track);
    }
    
    function text(PaperList $pl, PaperInfo $row) {
        if (!$pl->user->can_view_track($row)) {
            return "";
        }
        
        $track = $row->track_tag();
        if ($track === null || $track === "") {
            return "none";
        }
        
        // Get track display name
        $track_obj = $pl->conf->track_by_tag($track);
        if ($track_obj) {
            return $track_obj->name;
        }
        
        return $track;
    }
    
    static function expand($name, XtParams $xtp, $xfj, $m) {
        if ($name === "track") {
            return (object) [
                "name" => "track",
                "callback" => "+Track_PaperColumn",
                "order" => 90,
                "position" => 200
            ];
        }
        return null;
    }
    
    static function completions(Contact $user, $xfj) {
        if ($user->conf->has_tracks()) {
            return ["track"];
        }
        return [];
    }
} 