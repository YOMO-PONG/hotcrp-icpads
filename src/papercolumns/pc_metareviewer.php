<?php
// pc_metareviewer.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Metareviewer_PaperColumn extends PaperColumn {
    /** @var int */
    private $nameflags;
    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
        $this->override = PaperColumn::OVERRIDE_IFEMPTY;
    }
    function view_option_schema() {
        return self::user_view_option_schema();
    }
    function prepare(PaperList $pl, $visible) {
        if (!$pl->user->can_view_some_review_identity()) {
            return false;
        }
        $pl->conf->pc_set(); // prepare cache
        $pl->qopts["reviewSignatures"] = true;
        $this->nameflags = $this->user_view_option_name_flags($pl->conf);
        return true;
    }
    
    /** @return int */
    static private function metareviewer_cid(PaperList $pl, PaperInfo $row) {
        // 查找该论文的 metareview 负责人
        foreach ($row->reviews_as_display() as $rrow) {
            if ($rrow->reviewType === REVIEW_META 
                && $pl->user->can_view_review_identity($row, $rrow)) {
                return $rrow->contactId;
            }
        }
        return 0;
    }
    
    function sort_name() {
        return $this->sort_name_with_options("format");
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $ianno = $this->nameflags & NAME_L ? Contact::SORTSPEC_LAST : Contact::SORTSPEC_FIRST;
        return $pl->user_compare(self::metareviewer_cid($pl, $a), self::metareviewer_cid($pl, $b), $ianno);
    }
    function content_empty(PaperList $pl, PaperInfo $row) {
        return !self::metareviewer_cid($pl, $row);
    }
    function content(PaperList $pl, PaperInfo $row) {
        $cid = self::metareviewer_cid($pl, $row);
        if ($cid > 0) {
            return $pl->user_content($cid, $row, $this->nameflags);
        }
        return "";
    }
    function text(PaperList $pl, PaperInfo $row) {
        $cid = self::metareviewer_cid($pl, $row);
        if ($cid > 0) {
            return $pl->user_text($cid, $this->nameflags);
        }
        return "";
    }
} 