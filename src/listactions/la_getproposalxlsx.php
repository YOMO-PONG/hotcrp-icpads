<?php
// listactions/la_getproposalxlsx.php -- Export Track Proposal XLSX

class GetProposalXlsx_ListAction extends ListAction {
    function allow(Contact $user, Qrequest $qreq) {
        return $user->can_view_some_review(); // 至少是 PC/审稿人
    }

    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $conf = $user->conf;
        $rf = $conf->review_form();

        // 找 Overall merit 字段
        $overall_field = null;
        foreach ($rf->all_fields() as $f) {
            if ($f instanceof Discrete_ReviewField) {
                $sk = strtolower($f->search_keyword() ?? "");
                $nm = strtolower($f->name ?? "");
                if ($f->short_id === 's01' || $sk === 'ovemer' || $nm === 'overall merit') {
                    $overall_field = $f; break;
                }
            }
        }

        $rows = [];

        // Track 标签到全称的映射
        $tag_to_fullname = [
            'cloud-edge' => 'Cloud & Edge Computing',
            'wsmc' => 'Wireless Sensing & Mobile Computing',
            'ii-internet' => 'Industrial Informatics & Internet',
            'infosec' => 'Information Security',
            'sads' => 'System and Applied Data Science',
            'big-data-fm' => 'Big Data & Foundation Models',
            'aigc-mapc' => 'AIGC & Multi-Agent Parallel Computing',
            'dist-storage' => 'Distributed Storage',
            'ngm' => 'Next-Generation Mobile Networks and Connected Systems',
            'rfa' => 'RF Computing and AIoT Application',
            'dsui' => 'Distributed System and Ubiquitous Intelligence',
            'wma' => 'Wireless and Mobile AIoT',
            'bdmls' => 'Big Data and Machine Learning Systems',
            'ncea' => 'SS:Networked Computing for Embodied AI',
            'aimc' => 'Artificial Intelligence for Mobile Computing',
            'idpm' => 'Intelligent Data Processing & Management',
            'badv' => 'Blockchain & Activation of Data Value',
            'mwt' => 'SS:Millimeter-Wave and Terahertz Sensing and Networks',
            'idsia' => 'Interdisciplinary Distributed System and IoT Applications',
            'spmus' => 'Security and Privacy in Mobile and Ubiquitous Systems'
        ];
        $overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        foreach ($ssel->paper_set($user, ["allConflictType" => 1]) as $prow) {
            if (($why = $user->perm_view_paper($prow))) {
                continue;
            }

            // Track tag -> 名称（显示全称）
            $track_tag = null;
            if ($conf->has_tracks()) {
                foreach ($conf->track_tags() as $tt) {
                    if ($prow->has_tag($tt)) { $track_tag = $tt; break; }
                }
            }
            $track_name = $tag_to_fullname[$track_tag] ?? ($track_tag ?? "");

            // Authors
            $authors_disp = "";
            if ($user->allow_view_authors($prow)) {
                $parts = [];
                foreach ($prow->author_list() as $au) {
                    if ($au->is_empty()) continue;
                    $name = trim($au->name());
                    $aff = trim((string)$au->affiliation);
                    $country = "";
                    if ($au->email !== "" && ($u = $conf->user_by_email($au->email))) {
                        $country = $u->country_code();
                    }
                    $meta = [];
                    if ($aff !== "") $meta[] = $aff;
                    if ($country !== "") $meta[] = $country;
                    $parts[] = $meta ? ($name . " (" . join(", ", $meta) . ")") : $name;
                }
                $authors_disp = join("; ", $parts);
            }

            // Proposal 标签
            $proposal = $prow->has_viewable_tag("prop-accept", $user) ? "accept"
                : ($prow->has_viewable_tag("prop-discuss", $user) ? "discuss"
                   : ($prow->has_viewable_tag("prop-reject", $user) ? "reject" : ""));

            // 评审统计（排除 Meta）
            $rc = 0; $scores = [];
            if ($user->can_view_review($prow, null)) {
                $prow->ensure_full_reviews();
                foreach ($prow->viewable_reviews_as_display($user) as $rrow) {
                    if ($rrow->reviewSubmitted && $rrow->reviewType !== REVIEW_META) {
                        ++$rc;
                        if ($overall_field) {
                            $fv = $rrow->fval($overall_field);
                            if ($fv !== null) $scores[] = (float)$fv;
                        }
                    }
                }
            }
            $score_disp = $avg_disp = $span_disp = "";
            if (!empty($scores)) {
                $score_disp = join("/", array_map(function($v){ return (string)(int)$v; }, $scores));
                $avg_disp = number_format(array_sum($scores)/count($scores), 2, '.', '');
                $span_disp = number_format(max($scores)-min($scores), 2, '.', '');
            }

            $rows[] = [
                $track_name,
                (string)$prow->paperId,
                $prow->title,
                $authors_disp,
                (string)$rc,
                $score_disp,
                $avg_disp,
                $span_disp,
                $proposal
            ];
        }
        $user->set_overrides($overrides);

        // Header 顺序
        $header = [
            "Track","Paper ID","Title","Authors (affiliation, country)",
            "Reviews Completed","Score","Average Score","Span","Proposal"
        ];

        // 生成并下载
        $xlsx = new XlsxGenerator($conf->download_prefix . 'track-proposal.xlsx');
        $xlsx->add_sheet($header, $rows);
        $xlsx->emit();
    }
}


