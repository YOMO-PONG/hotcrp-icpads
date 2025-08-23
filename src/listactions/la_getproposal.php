<?php
// listactions/la_getproposal.php -- Export Track Proposal CSV
// Copyright (c) 2006-2025

class GetProposal_ListAction extends ListAction {
    /**
     * Permissions: allow PC (including chairs/managers). Data visibility for
     * authors/reviews will still respect per-paper permissions.
     */
    function allow(Contact $user, Qrequest $qreq) {
        return $user->isPC || $user->privChair;
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @param SearchSelection $ssel */
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $conf = $user->conf;
        $rf = $conf->review_form();

        // 找到“Overall merit”字段（用于 score/平均/跨度）
        $overall_field = null;
        foreach ($rf->all_fields() as $f) {
            if ($f instanceof Discrete_ReviewField) {
                $sk = strtolower($f->search_keyword() ?? "");
                $nm = strtolower($f->name ?? "");
                if ($f->short_id === 's01' || $sk === 'ovemer' || $nm === 'overall merit') {
                    $overall_field = $f;
                    break;
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
                // 无权限查看论文时跳过
                continue;
            }

            // Track 名称（基于标签匹配，并显示全称）
            $track_tag = null;
            if ($conf->has_tracks()) {
                $track_tags = $conf->track_tags();
                if (is_array($track_tags)) {
                    foreach ($track_tags as $ttag) {
                        if ($prow->has_tag($ttag)) {
                            $track_tag = $ttag;
                            break;
                        }
                    }
                }
            }
            $track_name = $tag_to_fullname[$track_tag] ?? ($track_tag ?? "");



            // Authors (affiliation, country)
            $authors_disp = "";
            if ($user->allow_view_authors($prow)) {
                $parts = [];
                foreach ($prow->author_list() as $au) {
                    if ($au->is_empty()) {
                        continue;
                    }
                    $name = trim($au->name());
                    $aff = trim((string) $au->affiliation);
                    $country = "";
                    if ($au->email !== "" && ($u = $conf->user_by_email($au->email))) {
                        $country = $u->country_code();
                    }
                    $meta = [];
                    if ($aff !== "") {
                        $meta[] = $aff;
                    }
                    if ($country !== "") {
                        $meta[] = $country;
                    }
                    if (!empty($meta)) {
                        $parts[] = $name . " (" . join(", ", $meta) . ")";
                    } else {
                        $parts[] = $name;
                    }
                }
                $authors_disp = join("; ", $parts);
            }

            // Reviews Completed, score 列（每个评审的 overall 分数）及平均/跨度
            $reviews_completed = 0;
            $scores = [];
            if ($user->can_view_review($prow, null)) {
                $prow->ensure_full_reviews();
                foreach ($prow->viewable_reviews_as_display($user) as $rrow) {
                    // 仅统计非元评审（Meta review）且已提交的评审
                    if ($rrow->reviewSubmitted && $rrow->reviewType !== REVIEW_META) {
                        ++$reviews_completed;
                        if ($overall_field) {
                            $fv = $rrow->fval($overall_field);
                            if ($fv !== null) {
                                $scores[] = (float) $fv;
                            }
                        }
                    }
                }
            }

            $score_disp = "";
            $avg_disp = "";
            $span_disp = "";
            if (!empty($scores)) {
                // score：各个评审的分数字符串，例如 3/4/5
                $score_disp = join("/", array_map(function($v){ return (string) (int) $v; }, $scores));
                $avg = array_sum($scores) / count($scores);
                $avg_disp = number_format($avg, 2, '.', '');
                $span = max($scores) - min($scores);
                $span_disp = number_format($span, 2, '.', '');
            }

            // Proposal: 根据标签 prop-accept / prop-discuss / prop-reject
            $proposal = "";
            if ($prow->has_viewable_tag("prop-accept", $user)) {
                $proposal = "accept";
            } else if ($prow->has_viewable_tag("prop-discuss", $user)) {
                $proposal = "discuss";
            } else if ($prow->has_viewable_tag("prop-reject", $user)) {
                $proposal = "reject";
            }

            $rows[] = [
                "Track" => $track_name,
                "Paper ID" => $prow->paperId,
                "Title" => $prow->title,
                "Authors (affiliation, country)" => $authors_disp,
                "Reviews Completed" => (string) $reviews_completed,
                "Score" => $score_disp,
                "Average Score" => $avg_disp,
                "Span" => $span_disp,
                "Decision" => $proposal
            ];
        }
        $user->set_overrides($overrides);

        // 排序：Track, Paper ID
        usort($rows, function($a, $b) {
            $ta = $a["Track"] ?? ""; $tb = $b["Track"] ?? "";
            if ($ta !== $tb) {
                return strcmp($ta, $tb);
            }
            return (int)$a["Paper ID"] <=> (int)$b["Paper ID"];
        });

        // Header 顺序与命名
        $header = [
            "Track",
            "Paper ID",
            "Title",
            "Authors (affiliation, country)",
            "Reviews Completed",
            "Score",
            "Average Score",
            "Span",
            "Decision"
        ];

        // 导出 CSV
        return $conf->make_csvg("decision-proposal")
            ->select($header)
            ->append($rows);
    }
}


