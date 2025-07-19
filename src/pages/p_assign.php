<?php
// pages/p_assign.php -- HotCRP per-paper assignment/conflict management page
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Assign_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;
    /** @var PaperInfo */
    public $prow;
    /** @var PaperTable */
    public $pt;
    /** @var bool */
    public $allow_view_authors;
    /** @var MessageSet */
    private $ms;

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
        $this->ms = new MessageSet;
    }

    function error_exit(...$mls) {
        PaperTable::print_header($this->pt, $this->qreq, true);
        $this->conf->feedback_msg(...$mls);
        $this->qreq->print_footer();
        throw new PageCompletion;
    }

    function assign_load() {
        try {
            $pr = new PaperRequest($this->qreq, true);
            $this->qreq->set_paper($pr->prow);
            $this->prow = $pr->prow;
            if (($whynot = $this->user->perm_request_review($this->prow, null, false))) {
                $this->pt = new PaperTable($this->user, $this->qreq, $this->prow);
                throw $whynot;
            }
        } catch (Redirection $redir) {
            throw $redir;
        } catch (FailureReason $perm) {
            $perm->set("expand", true);
            $this->error_exit($perm->message_list());
        }
    }

    function handle_pc_update() {
        $reviewer = $this->qreq->reviewer;
        
        // Get selected review round from request (R1 or R2)
        $selected_round = $this->qreq->selected_round ?? "R1";
        if (($rname = $this->conf->sanitize_round_name($selected_round)) === "") {
            $rname = "R1"; // Default to R1
        }
        $round = CsvGenerator::quote(":" . (string) $rname);

        $confset = $this->conf->conflict_set();
        $acceptable_review_types = [];
        foreach ([0, REVIEW_PC, REVIEW_SECONDARY, REVIEW_PRIMARY, REVIEW_META] as $t) {
            $acceptable_review_types[] = (string) $t;
        }

        $prow = $this->prow;
        $paper_track = $this->get_paper_track_tag($prow);
        $t = ["paper,action,email,round,conflict\n"];
        
        foreach ($this->conf->pc_members() as $cid => $p) {
            if ($reviewer
                && strcasecmp($p->email, $reviewer) != 0
                && (string) $p->contactId !== $reviewer) {
                continue;
            }

            // Apply track filtering - only allow operations on track members/chairs
            $is_trackchair = $this->is_track_chair($p, $paper_track);
            
            // Skip PC Chair only if they are not also a track chair
            if ($p->privChair && !$is_trackchair) {
                continue;
            }
            
            // Check if PC member is assignable to this track
            if (!$p->pc_track_assignable($prow) && !$prow->has_reviewer($p) && !$is_trackchair) {
                continue;
            }
            
            // Filter by track membership if paper has a track, but always include track chairs
            if ($paper_track && !$this->is_track_member($p, $paper_track) && !$is_trackchair) {
                continue;
            }

            if (isset($this->qreq["assrev{$prow->paperId}u{$cid}"])) {
                $assignment = $this->qreq["assrev{$prow->paperId}u{$cid}"];
            } else if (isset($this->qreq["pcs{$cid}"])) {
                $assignment = $this->qreq["pcs{$cid}"];
            } else {
                continue;
            }

            if (in_array($assignment, $acceptable_review_types, true)) {
                $revtype = ReviewInfo::unparse_assigner_action((int) $assignment);
                $conftype = "off";
            } else if ($assignment === "-1") {
                $revtype = "clearreview";
                $conftype = "on";
            } else if (($type = ReviewInfo::parse_type($assignment, true))) {
                $revtype = ReviewInfo::unparse_assigner_action($type);
                $conftype = "off";
            } else if (($ct = $confset->parse_assignment($assignment, 0)) !== false) {
                $revtype = "clearreview";
                $conftype = $assignment;
            } else {
                continue;
            }

            $myround = $round;
            if (isset($this->qreq["rev_round{$prow->paperId}u{$cid}"])) {
                $x = $this->conf->sanitize_round_name($this->qreq["rev_round{$prow->paperId}u{$cid}"]);
                if ($x !== false) {
                    $myround = $x === "" ? $rname : CsvGenerator::quote($x);
                }
            }

            $user = CsvGenerator::quote($p->email);
            $t[] = "{$prow->paperId},conflict,{$user},,{$conftype}\n";
            $t[] = "{$prow->paperId},{$revtype},{$user},{$myround}\n";
        }

        $aset = new AssignmentSet($this->user);
        $aset->set_override_conflicts(true);
        $aset->enable_papers($this->prow);
        $aset->parse(join("", $t));
        $ok = $aset->execute();
        if ($this->qreq->ajax) {
            json_exit($aset->json_result());
        }
        $aset->feedback_msg(AssignmentSet::FEEDBACK_ASSIGN);
        $ok && $this->conf->redirect_self($this->qreq);
    }

    /** @return never
     * @throws Redirection */
    private function redirect_requestreview() {
        $this->conf->redirect_self($this->qreq, ["email" => null, "given_name" => null, "family_name" => null, "affiliation" => null, "round" => null, "reason" => null, "override" => null, "denyreview" => null, "retractreview" => null, "undeclinereview" => null]);
    }

    function handle_requestreview() {
        $result = RequestReview_API::requestreview($this->user, $this->qreq, $this->prow);
        if ($result->content["ok"]) {
            assert(is_array($result->content["message_list"]));
            $this->conf->feedback_msg($result->content["message_list"]);
            $this->redirect_requestreview();
        }
        $emx = null;
        foreach ($result->content["message_list"] ?? [] as $mx) {
            '@phan-var-force MessageItem $mx';
            if ($mx->field === "email") {
                $emx = $mx;
            } else if ($mx->field === "override" && $emx) {
                $emx->message .= "<p>To request a review anyway, either retract the refusal or submit again with \"Override\" checked.</p>";
            }
        }
        $this->ms->append_list($result->content["message_list"] ?? []);
        $this->assign_load();
    }

    function handle_denyreview() {
        $result = RequestReview_API::denyreview($this->user, $this->qreq, $this->prow);
        if ($result->content["ok"]) {
            $this->conf->success_msg("<0>Proposed reviewer denied");
            $this->redirect_requestreview();
        }
        $this->ms->append_list($result->content["message_list"] ?? []);
        $this->assign_load();
    }

    function handle_retractreview() {
        $result = RequestReview_API::retractreview($this->user, $this->qreq, $this->prow);
        if ($result->content["ok"]) {
            if ($result->content["notified"]) {
                $this->conf->feedback_msg(
                    MessageItem::success("<0>Review retracted"),
                    MessageItem::inform("<0>The reviewer was notified that they do not need to complete their review.")
                );
            } else {
                $this->conf->success_msg("<0>Review request retracted");
            }
            $this->redirect_requestreview();
        }
        $this->ms->append_list($result->content["message_list"] ?? []);
        $this->assign_load();
    }

    function handle_undeclinereview() {
        $result = RequestReview_API::undeclinereview($this->user, $this->qreq, $this->prow);
        if ($result->content["ok"]) {
            $email = $this->qreq->email ? : "You";
            $this->conf->feedback_msg(
                MessageItem::success("<0>Review refusal removed"),
                MessageItem::inform("<0>{$email} may now be asked again to review this submission.")
            );
            $this->redirect_requestreview();
        }
        $this->ms->append_list($result->content["message_list"] ?? []);
        $this->assign_load();
    }

    function handle_request() {
        $qreq = $this->qreq;
        if (isset($qreq->update) && $qreq->valid_post()) {
            if ($this->user->allow_administer($this->prow)) {
                $this->handle_pc_update();
            } else if ($this->qreq->ajax) {
                json_exit(JsonResult::make_error(403, "<0>Only administrators can assign reviews"));
            }
        }
        if ((isset($qreq->requestreview) || isset($qreq->approvereview))
            && $qreq->valid_post()) {
            $this->handle_requestreview();
        }
        if ((isset($qreq->deny) || isset($qreq->denyreview))
            && $qreq->valid_post()) {
            $this->handle_denyreview();
        }
        if (isset($qreq->retractreview)
            && $qreq->valid_post()) {
            $this->handle_retractreview();
        }
        if (isset($qreq->undeclinereview)
            && $qreq->valid_post()) {
            $this->handle_undeclinereview();
        }
        $this->conf->feedback_msg($this->ms);
    }

    /** @param ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rrow */
    private function print_reqrev_main($rrow, $namex, $time) {
        $rname = $rrow->status_title(true) . " (" . $rrow->status_description() . ")";
        if ($this->user->can_view_review($this->prow, $rrow)) {
            $rname = Ht::link($rname, $this->prow->reviewurl(["r" => $rrow->reviewId]));
        }
        echo $rname, ': ', $namex,
            '</div><div class="f-d"><ul class="x mb-0">';
        echo '<li>requested';
        if ($rrow->timeRequested) {
            echo ' ', $this->conf->unparse_time_relative((int) $rrow->timeRequested);
        }
        if ($rrow->requestedBy == $this->user->contactId) {
            echo " by you";
        } else if ($this->user->can_view_review_requester($this->prow, $rrow)) {
            echo " by ", $this->user->reviewer_html_for($rrow->requestedBy);
        }
        echo '</li>';
        if ($rrow->reviewStatus === ReviewInfo::RS_ACKNOWLEDGED) {
            echo '<li>accepted';
            if ($time) {
                echo ' ', $this->conf->unparse_time_relative($time);
            }
            echo '</li>';
        }
        echo '</ul></div>';
    }

    /** @param ReviewRequestInfo $rrow */
    private function print_reqrev_proposal($rrow, $namex, $rrowid) {
        echo "Review proposal: ", $namex, '</div><div class="f-d"><ul class="x mb-0">';
        if ($rrow->timeRequested
            || $this->user->can_view_review_requester($this->prow, $rrow)) {
            echo '<li>proposed';
            if ($rrow->timeRequested) {
                echo ' ', $this->conf->unparse_time_relative((int) $rrow->timeRequested);
            }
            if ($rrow->requestedBy == $this->user->contactId) {
                echo " by you";
            } else if ($this->user->can_view_review_requester($this->prow, $rrow)) {
                echo " by ", $this->user->reviewer_html_for($rrow->requestedBy);
            }
            echo '</li>';
        }
        $reason = $rrow->reason;
        if ($this->allow_view_authors
            && ($potconf = $this->prow->potential_conflict_html($rrowid, true))) {
            foreach ($potconf->messages as $ml) {
                echo '<li class="fx">', $potconf->render_ul_item(null, "possible conflict: ", $ml), '</li>';
            }
            $reason = $reason ? : "This reviewer appears to have a conflict with the submission authors.";
        }
        echo '</ul></div>';
        return $reason;
    }

    /** @param ReviewRefusalInfo $rrow */
    private function print_reqrev_denied($rrow, $namex) {
        echo "Declined request: ", $namex,
            '</div><div class="f-d fx"><ul class="x mb-0">';
        if ($rrow->timeRequested
            || $this->user->can_view_review_requester($this->prow, $rrow)) {
            echo '<li>requested';
            if ($rrow->timeRequested) {
                echo ' ', $this->conf->unparse_time_relative((int) $rrow->timeRequested);
            }
            if ($rrow->requestedBy == $this->user->contactId) {
                echo " by you";
            } else if ($this->user->can_view_review_requester($this->prow, $rrow)) {
                echo " by ", $this->user->reviewer_html_for($rrow->requestedBy);
            }
            echo '</li>';
        }
        echo '<li>declined';
        if ($rrow->timeRefused) {
            echo ' ', $this->conf->unparse_time_relative((int) $rrow->timeRefused);
        }
        if ($rrow->refusedBy
            && (!$rrow->contactId || $rrow->contactId != $rrow->refusedBy)) {
            if ($rrow->refusedBy == $this->user->contactId) {
                echo " by you";
            } else {
                echo " by ", $this->user->reviewer_html_for($rrow->refusedBy);
            }
        }
        echo '</li>';
        if ((string) $rrow->reason !== ""
            && $rrow->reason !== "request denied by chair") {
            echo '<li class="mb-0-last-child">', Ht::format0("reason: " . $rrow->reason), '</li>';
        }
        echo '</ul></div>';
    }

    /** @param ReviewInfo|ReviewRequestInfo|ReviewRefusalInfo $rrow */
    private function print_reqrev($rrow, $time) {
        echo '<div class="ctelt"><div class="ctelti has-fold';
        if ($rrow->reviewType === REVIEW_REQUEST
            && ($this->user->can_administer($this->prow)
                || $rrow->requestedBy == $this->user->contactId)) {
            echo ' foldo';
        } else {
            echo ' foldc';
        }
        echo '">';

        // create contact-like for identity
        $rrowid = $rrow->reviewer();

        // render name
        $actas = "";
        if (isset($rrow->contactId) && $rrow->contactId > 0) {
            $name = $this->user->reviewer_html_for($rrowid);
            if ($rrow->contactId !== $this->user->contactId
                && $this->user->privChair
                && $this->user->allow_administer($this->prow)) {
                $actas = ' ' . Ht::link(Ht::img("viewas.png", "[Act as]", ["title" => "Become user"]),
                    $this->prow->reviewurl(["actas" => $rrowid->email]));
            }
        } else {
            $name = Text::nameo_h($rrowid, NAME_P);
        }
        $fullname = $name;
        if ((string) $rrowid->affiliation !== "") {
            $fullname .= ' <span class="auaff">(' . htmlspecialchars($rrowid->affiliation) . ')</span>';
        }
        if ((string) $rrowid->firstName !== ""
            || (string) $rrowid->lastName !== "") {
            $fullname .= ' &lt;' . Ht::link(htmlspecialchars($rrowid->email), "mailto:" . $rrowid->email, ["class" => "q"]) . '&gt;';
        }

        $namex = "<span class=\"fn\">{$name}</span><span class=\"fx\">{$fullname}</span>{$actas}";
        if ($rrow->reviewType !== REVIEW_REFUSAL) {
            $namex .= ' ' . review_type_icon($rrowid->isPC ? REVIEW_PC : REVIEW_EXTERNAL, "rtinc");
        }
        if ($this->user->can_view_review_meta($this->prow, $rrow)) {
            $namex .= ReviewInfo::make_round_h($this->conf, $rrow->reviewRound);
        }

        // main render
        echo '<div class="ui js-foldup"><button type="button" class="q ui js-foldup">', expander(null, 0), '</button>';
        $reason = null;
        if ($rrow->reviewType >= 0) {
            $this->print_reqrev_main($rrow, $namex, $time);
        } else if ($rrow->reviewType === REVIEW_REQUEST) {
            $reason = $this->print_reqrev_proposal($rrow, $namex, $rrowid);
        } else {
            $this->print_reqrev_denied($rrow, $namex);
        }

        // render form
        if ($this->user->can_administer($this->prow)
            || ($rrow->reviewType !== REVIEW_REFUSAL
                && $this->user->contactId > 0
                && $rrow->requestedBy == $this->user->contactId)) {
            echo Ht::form($this->conf->hoturl("=assign", [
                    "p" => $this->prow->paperId, "action" => "managerequest",
                    "email" => $rrowid->email, "round" => $rrow->reviewRound
                ]), ["class" => "fx"]);
            if (!isset($rrow->contactId) || !$rrow->contactId) {
                echo Ht::hidden("given_name", $rrowid->firstName),
                    Ht::hidden("family_name", $rrowid->lastName),
                    Ht::hidden("affiliation", $rrowid->affiliation);
            }
            $buttons = [];
            if ($reason) {
                echo Ht::hidden("reason", $reason);
            }
            if ($rrow->reviewType === REVIEW_REQUEST
                && $this->user->can_administer($this->prow)) {
                echo Ht::hidden("override", 1);
                $buttons[] = Ht::submit("approvereview", "Approve proposal", ["class" => "btn-sm btn-success"]);
                $buttons[] = Ht::submit("denyreview", "Deny proposal", ["class" => "btn-sm ui js-deny-review-request"]); // XXX reason
            }
            if ($rrow->reviewType >= 0 && $rrow->reviewStatus > ReviewInfo::RS_ACKNOWLEDGED) {
                $buttons[] = Ht::submit("retractreview", "Retract review", ["class" => "btn-sm"]);
            } else if ($rrow->reviewType >= 0) {
                $buttons[] = Ht::submit("retractreview", "Retract review request", ["class" => "btn-sm"]);
            } else if ($rrow->reviewType === REVIEW_REQUEST
                       && $this->user->contactId > 0
                       && $rrow->requestedBy == $this->user->contactId) {
                $buttons[] = Ht::submit("retractreview", "Retract proposal", ["class" => "btn-sm"]);
            } else if ($rrow->reviewType === REVIEW_REFUSAL) {
                $buttons[] = Ht::submit("undeclinereview", "Remove declined request", ["class" => "btn-sm"]);
                $buttons[] = '<span class="hint">(allowing review to be reassigned)</span>';
            }
            if ($buttons) {
                echo '<div class="btnp">', join("", $buttons), '</div>';
            }
            echo '</form>';
        }

        echo '</div></div>';
    }

    /** @param Contact $pc
     * @param AssignmentCountSet $acs 
     * @param AssignmentCountSet $track_acs */
    private function print_pc_assignment($pc, $acs, $track_acs = null, $auto_assigned = [], $reviewer_data = null, $is_assigned = false, $is_recommended = false) {
        // first, name and assignment
        $ct = $this->prow->conflict_type($pc);
        $rrow = $this->prow->review_by_user($pc);
        if (Conflict::is_author($ct)) {
            $revtype = -2;
        } else {
            $revtype = $rrow ? $rrow->reviewType : 0;
        }
        $crevtype = $revtype;
        if ($crevtype == 0 && Conflict::is_conflicted($ct)) {
            $crevtype = -1;
        }
        $potconf = null;
        if ($this->allow_view_authors && $revtype != -2) {
            $potconf = $this->prow->potential_conflict_html($pc, !Conflict::is_conflicted($ct));
        }

        // Check if this reviewer is auto-assigned
        // No auto assignment in single round review
        
        // Auto-set recommended reviewers as Primary if not already assigned
        if ($is_recommended && $revtype == 0 && !Conflict::is_conflicted($ct)) {
            $revtype = REVIEW_PRIMARY;
            $crevtype = REVIEW_PRIMARY;
        }

        // Get reviewer data if available
        $topic_score = $reviewer_data ? $reviewer_data['score'] : $this->prow->topic_interest_score($pc);
        $is_trackchair_data = $reviewer_data ? $reviewer_data['is_trackchair'] : false;
        
        // Determine row classes
        $row_classes = ['reviewer-list-row'];
        if ($is_assigned) {
            $row_classes[] = 'assigned-reviewer';
        }
        if ($is_recommended) {
            $row_classes[] = 'recommended-reviewer';
        }
        
        // List row format
        echo '<div class="', implode(' ', $row_classes), '" data-pid="', $this->prow->paperId,
            '" data-uid="', $pc->contactId,
            '" data-review-type="', $revtype;
        if (Conflict::is_conflicted($ct)) {
            echo '" data-conflict-type="1';
        }
        if (!$revtype && $this->prow->review_refusals_by_user($pc)) {
            echo '" data-assignment-declined="1';
        }
        if ($rrow && $rrow->reviewRound && ($rn = $rrow->round_name())) {
            echo '" data-review-round="', htmlspecialchars($rn);
        }
        if ($rrow && $rrow->reviewStatus >= ReviewInfo::RS_DRAFTED) {
            echo '" data-review-in-progress="';
        }
        echo '">';
        
        // Name column
        echo '<div class="reviewer-name-col">';
        
        // Status indicators
        if ($is_assigned) {
            echo '<span class="assigned-indicator" title="Currently assigned">✓</span> ';
        } else if ($is_recommended) {
            echo '<span class="recommended-indicator" title="Recommended reviewer">⭐</span> ';
        }
        
        // Track chair indicator
        if ($is_trackchair_data) {
            echo '<span class="trackchair-badge" title="Track Chair">TC</span> ';
        }
        
        echo $this->user->reviewer_html_for($pc);
        if ($crevtype != 0) {
            if ($rrow) {
                echo ' ', review_type_icon($crevtype, $rrow->icon_classes("ml-1")), $rrow->round_h();
            } else {
                echo ' ', review_type_icon($crevtype, "ml-1");
            }
        }
        echo '</div>';
        
        // Topics Score column
        echo '<div class="reviewer-score-col">';
        echo '<span class="topic-score">' . $topic_score . '</span>';
        echo '</div>';
        
        // Global Review count column
        echo '<div class="reviewer-count-col">';
        $ac = $acs->get($pc->contactId);
        if ($ac->rev === 0) {
            echo "0";
        } else {
            $review_class = '';
            $review_title = '';
            
            // Add warning styles for high workload
            if ($ac->rev >= 6) {
                $review_class = ' class="high-workload"';
                $review_title = ' title="High review load - may affect recommendation priority"';
            } else if ($ac->rev >= 4) {
                $review_class = ' class="medium-workload"';
                $review_title = ' title="Medium review load"';
            }
            
            echo '<a class="q"' . $review_class . $review_title . ' href="',
                $this->conf->hoturl("search", "q=re:" . urlencode($pc->email)), '">',
                $ac->rev, "</a>";
            if ($ac->pri && $ac->pri < $ac->rev) {
                echo '<br><small>(<a class="q" href="',
                    $this->conf->hoturl("search", "q=pri:" . urlencode($pc->email)),
                    "\">{$ac->pri} pri</a>)</small>";
            }
        }
        echo '</div>';
        
        // Track Review count column
        echo '<div class="reviewer-track-count-col">';
        if ($track_acs) {
            $track_ac = $track_acs->get($pc->contactId);
            if ($track_ac->rev === 0) {
                echo "0";
            } else {
                echo '<span class="track-review-count" title="Reviews in this track">', $track_ac->rev, '</span>';
                if ($track_ac->pri && $track_ac->pri < $track_ac->rev) {
                    echo '<br><small>(', $track_ac->pri, ' pri)</small>';
                }
            }
        } else {
            echo "-";
        }
        echo '</div>';
        
        // Assignment controls column
        echo '<div class="reviewer-assign-col">';
        if ($this->user->allow_administer($this->prow)) {
            $inputName = "assrev{$this->prow->paperId}u{$pc->contactId}";
            echo '<select name="', $inputName, '" class="assignment-selector js-assign-review">';
            
            // Options with current selection
            echo '<option value="0"', ($revtype == 0 && !Conflict::is_conflicted($ct) ? ' selected' : ''), '>None</option>';
            echo '<option value="4"', ($revtype == 4 ? ' selected' : ''), '>Primary</option>';
            echo '<option value="5"', ($revtype == 5 ? ' selected' : ''), '>Metareview</option>';
            
            if (Conflict::is_conflicted($ct)) {
                echo '<option value="-1" selected>Conflict</option>';
            }
            
            echo '</select>';
        } else {
            // Display current status without edit capability
            if ($revtype == 4) {
                echo '<span class="assignment-status primary">Primary</span>';
            } else if ($revtype == 5) {
                echo '<span class="assignment-status metareview">Metareview</span>';
            } else if (Conflict::is_conflicted($ct)) {
                echo '<span class="assignment-status conflict">Conflict</span>';
            } else {
                echo '<span class="assignment-status">None</span>';
            }
        }
        echo '</div>';
        
        echo "</div>\n"; // .reviewer-list-row
    }

    /**
     * Calculate and return recommended reviewers based on topic scores and review workload
     * @param int $limit Maximum number of recommendations
     * @param bool $exclude_conflicts Whether to exclude conflicted reviewers
     * @param AssignmentCountSet $acs Assignment count set for reviewer workload
     * @return array{recommended: list<Contact>, all: list<Contact>, scores: array<int,array{score:int,conflict:bool,review_count:int}>}
     */
    private function get_recommended_reviewers($limit = 10, $exclude_conflicts = true, $acs = null) {
        $prow = $this->prow;
        $user = $this->user;
        $all_pc = [];
        $reviewer_data = [];
        
        // Get paper's track tag
        $paper_track = $this->get_paper_track_tag($prow);
        
        // Collect all eligible PC members with their scores and conflict status
        foreach ($this->conf->pc_members() as $pc) {
            // Include track chairs for this paper's track
            $is_trackchair = $this->is_track_chair($pc, $paper_track);
            
            // Skip PC Chair only if they are not also a track chair
            if ($pc->privChair && !$is_trackchair) {
                continue;
            }
            
            // Check if PC member is assignable to this track
            if (!$pc->pc_track_assignable($prow) && !$prow->has_reviewer($pc) && !$is_trackchair) {
                continue;
            }
            
            // Filter by track membership if paper has a track, but always include track chairs
            if ($paper_track && !$this->is_track_member($pc, $paper_track) && !$is_trackchair) {
                continue;
            }
            
            $topic_score = $prow->topic_interest_score($pc);
            $conflict_type = $prow->conflict_type($pc);
            $is_conflicted = Conflict::is_conflicted($conflict_type) || Conflict::is_author($conflict_type);
            
            // Check for potential conflicts (affiliation matching, etc.)
            $has_potential_conflict = $prow->potential_conflict($pc);
            
            // Skip conflicted reviewers and those with potential conflicts from all lists
            if ($exclude_conflicts && ($is_conflicted || $has_potential_conflict)) {
                continue;
            }
            
            // Get current review count for this reviewer
            $review_count = $acs ? $acs->get($pc->contactId)->rev : 0;
            
            $reviewer_data[$pc->contactId] = [
                'contact' => $pc,
                'score' => $topic_score,
                'conflict' => $is_conflicted || $has_potential_conflict,
                'preference' => $prow->preference($pc),
                'review_count' => $review_count,
                'is_trackchair' => $is_trackchair
            ];
            
            $all_pc[] = $pc;
        }
        
        // Enhanced sorting: topic score (descending), global review count (ascending), preference (descending)
        uasort($reviewer_data, function($a, $b) {
            // 1. First sort by topic score (higher score first)
            $score_cmp = $b['score'] <=> $a['score'];
            if ($score_cmp !== 0) return $score_cmp;
            
            // 2. Then sort by global review count (lower count first) - this ensures less loaded reviewers are prioritized
            $count_cmp = $a['review_count'] <=> $b['review_count'];
            if ($count_cmp !== 0) return $count_cmp;
            
            // 3. Finally sort by preference (higher preference first)
            $pref_a = $a['preference']->preference;
            $pref_b = $b['preference']->preference;
            return $pref_b <=> $pref_a;
        });
        
        // Filter recommendations with workload consideration
        $recommended = [];
        $count = 0;
        
        foreach ($reviewer_data as $cid => $data) {
            if ($count >= $limit) break;
            
            // Core filtering logic: skip reviewers with more than 6 reviews, even if topic score is high
            if ($data['review_count'] > 6) {
                continue;
            }
            
            $recommended[] = $data['contact'];
            $count++;
        }
        
        // Prepare scores array for JavaScript
        $scores = [];
        foreach ($reviewer_data as $cid => $data) {
            $scores[$cid] = [
                'score' => $data['score'],
                'conflict' => $data['conflict'],
                'review_count' => $data['review_count'],
                'is_trackchair' => $data['is_trackchair']
            ];
        }
        
        return [
            'recommended' => $recommended,
            'all' => $all_pc,
            'scores' => $scores
        ];
    }

    /**
     * Get the track tag of a paper
     * @param PaperInfo $prow
     * @return string|null
     */
    private function get_paper_track_tag(PaperInfo $prow) {
        $all_tags = $prow->all_tags_text();
        if (empty($all_tags)) {
            return null;
        }
        
        // Get all track tags from conference
        $track_tags = $this->conf->track_tags();
        if (empty($track_tags)) {
            return null;
        }
        
        // Find which track this paper belongs to
        foreach ($track_tags as $track_tag) {
            if ($prow->has_tag($track_tag)) {
                return $track_tag;
            }
        }
        
        return null;
    }
    
    /**
     * Check if a PC member is a member of the specified track
     * @param Contact $pc
     * @param string $track_tag
     * @return bool
     */
    private function is_track_member(Contact $pc, $track_tag) {
        $member_tag = "trackmember-{$track_tag}";
        return $pc->has_tag($member_tag);
    }
    
    /**
     * Check if a PC member is a chair of the specified track
     * @param Contact $pc
     * @param string $track_tag
     * @return bool
     */
    private function is_track_chair(Contact $pc, $track_tag) {
        if (!$track_tag) {
            return false;
        }
        $chair_tag = "trackchair-{$track_tag}";
        return $pc->has_tag($chair_tag);
    }

    /**
     * Print the recommended reviewers section
     * @param array $recommended_data Result from get_recommended_reviewers()
     * @param AssignmentCountSet $acs Global assignment count set
     * @param AssignmentCountSet $track_acs Track-specific assignment count set
     */
    private function print_all_reviewers_unified($recommended_data, $acs, $track_acs = null) {
        $recommended = $recommended_data['recommended'];
        $all_reviewers = $recommended_data['all'];
        $scores = $recommended_data['scores'];
        
        // Get paper track information for display
        $paper_track = $this->get_paper_track_tag($this->prow);
        $track_display_name = $paper_track ? htmlspecialchars($this->get_track_display_name($paper_track)) : "default";
        
        echo '<div id="unified-reviewer-view">',
            '<div class="flex-container mb-3">',
            '<h3 class="revcard-subhead">PC Members Assignment</h3>',
            '</div>';
            
        // Add recommendation info panel only if there are recommendations
        if (!empty($recommended)) {
            echo '<div class="recommendation-panel mb-3">',
                '<div class="flex-container">',
                '<div class="recommendation-info">',
                '<span class="info-icon">⭐</span> ',
                '<span class="info-text">Top 3 recommended reviewers based on topic match and workload are marked with ⭐ and pre-selected as Primary reviewers</span>',
                '</div>',
                '</div>',
                '</div>';
        } else {
            echo '<div class="info-panel mb-3">',
                '<div class="flex-container">',
                '<div class="no-recommendation-info">',
                '<span class="info-text">Assignments already exist - showing all PC members in the track of <strong>' . $track_display_name . '</strong> for review management</span>',
                '</div>',
                '</div>',
                '</div>';
        }
        
        // Search functionality
        echo '<div class="mb-3">',
            '<input type="text" id="reviewer-search" class="fullw" placeholder="Search reviewer name..." />',
            '</div>';
        
        if (empty($all_reviewers)) {
            echo '<p class="feedback is-note">No PC members found.</p>';
        } else {
            // Combine and sort all reviewers
            $all_pc_data = [];
            $recommended_ids = array_column($recommended, 'contactId');
            
            // Get all PC members with their data
            foreach ($all_reviewers as $pc) {
                // Always use global assignment counts for review_count
                $global_review_count = $acs ? $acs->get($pc->contactId)->rev : 0;
                
                // If scores are empty (no recommendations), calculate data on the fly
                if (empty($scores)) {
                    $reviewer_info = [
                        'score' => $this->prow->topic_interest_score($pc),
                        'conflict' => false,
                        'review_count' => $global_review_count,
                        'is_trackchair' => $this->is_track_chair($pc, $this->get_paper_track_tag($this->prow))
                    ];
                } else {
                    $reviewer_info = $scores[$pc->contactId] ?? [
                        'score' => $this->prow->topic_interest_score($pc),
                        'conflict' => false,
                        'review_count' => $global_review_count,
                        'is_trackchair' => $this->is_track_chair($pc, $this->get_paper_track_tag($this->prow))
                    ];
                    // Ensure we use the global review count
                    $reviewer_info['review_count'] = $global_review_count;
                }
                
                // Check if currently assigned
                $rrow = $this->prow->review_by_user($pc);
                $is_assigned = $rrow && $rrow->reviewType > 0;
                $is_recommended = in_array($pc->contactId, $recommended_ids);
                
                $all_pc_data[] = [
                    'contact' => $pc,
                    'reviewer_info' => $reviewer_info,
                    'is_assigned' => $is_assigned,
                    'is_recommended' => $is_recommended,
                    'sort_priority' => $is_assigned ? 1 : ($is_recommended ? 2 : 3)
                ];
            }
            
            // Sort: assigned first, then recommended, then others
            usort($all_pc_data, function($a, $b) {
                if ($a['sort_priority'] !== $b['sort_priority']) {
                    return $a['sort_priority'] <=> $b['sort_priority'];
                }
                // Within same priority, sort by topic score first
                $score_cmp = $b['reviewer_info']['score'] <=> $a['reviewer_info']['score'];
                if ($score_cmp !== 0) {
                    return $score_cmp;
                }
                // If topic scores are equal, sort by global review count (ascending - fewer reviews first)
                return $a['reviewer_info']['review_count'] <=> $b['reviewer_info']['review_count'];
            });
            
            // List header
            echo '<div class="reviewer-list-container">',
                '<div class="reviewer-list-header">',
                '<div class="reviewer-name-header">Name</div>',
                '<div class="reviewer-score-header">Topic Score</div>',
                '<div class="reviewer-count-header">Global Reviews</div>',
                '<div class="reviewer-track-count-header">Track Reviews</div>',
                '<div class="reviewer-assign-header">Assignment</div>',
                '</div>';
                
            echo '<div class="reviewer-list-body has-assignment-set need-assignment-change">';
            foreach ($all_pc_data as $data) {
                $this->print_pc_assignment(
                    $data['contact'], 
                    $acs, 
                    $track_acs,
                    [], // no auto_assigned needed
                    $data['reviewer_info'],
                    $data['is_assigned'],
                    $data['is_recommended']
                );
            }
            echo '</div>';
            echo '</div>';
        }
        
        echo '</div>';
            
        // Add CSS and JavaScript for reviewer recommendation functionality
        echo '
<style>
.flex-container { display: flex; align-items: center; justify-content: space-between; }
.pc-ctable-container { position: relative; }
.revcard-subhead { margin: 0; font-size: 1.1em; font-weight: bold; }

/* List-style reviewer display */
.reviewer-list-container {
    border: 1px solid #ddd;
    border-radius: 0.5rem;
    overflow: hidden;
    margin-bottom: 1rem;
}

.reviewer-list-header {
    display: grid;
    grid-template-columns: 3fr 1fr 1fr 1fr 1.5fr;
    gap: 1rem;
    background: #f8f9fa;
    padding: 0.75rem;
    font-weight: bold;
    border-bottom: 2px solid #dee2e6;
}

.reviewer-list-body {
    background: white;
    display: flex;
    flex-direction: column;
}

.reviewer-list-row {
    display: grid;
    grid-template-columns: 3fr 1fr 1fr 1fr 1.5fr;
    gap: 1rem;
    padding: 0.75rem;
    border-bottom: 1px solid #eee;
    align-items: center;
    transition: background-color 0.2s;
}

.reviewer-list-row.assigned-reviewer {
    background-color: #d4edda !important;
    border-left: 4px solid #28a745;
    order: -2;
}

.reviewer-list-row.recommended-reviewer {
    background-color: #fff3cd;
    border-left: 3px solid #ffc107;
    order: -1;
}

.reviewer-list-row.recommended-reviewer .assignment-selector {
    background-color: #e3f2fd;
    border-color: #2196f3;
}

.reviewer-list-row:hover {
    background-color: #f8f9fa;
}

.reviewer-list-row.auto-assigned {
    border-left: 4px solid #28a745;
    background-color: #f8fff9;
}

.reviewer-name-col {
    font-weight: 500;
}

.reviewer-score-col {
    text-align: center;
}

.topic-score {
    background: #007bff;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.85em;
    font-weight: bold;
}

.reviewer-count-col {
    text-align: center;
}

.reviewer-track-count-col {
    text-align: center;
}

.track-review-count {
    background: #28a745;
    color: white;
    padding: 0.25rem 0.5rem;
    border-radius: 1rem;
    font-size: 0.85em;
    font-weight: bold;
}

.high-workload {
    color: #dc3545 !important;
    font-weight: bold;
}

.medium-workload {
    color: #fd7e14 !important;
    font-weight: bold;
}

.reviewer-assign-col {
    text-align: center;
}

.assignment-selector {
    padding: 0.25rem 0.5rem;
    border: 1px solid #ced4da;
    border-radius: 0.25rem;
    background: white;
    font-size: 0.9em;
    min-width: 100px;
}

.assignment-status {
    padding: 0.25rem 0.5rem;
    border-radius: 0.25rem;
    font-size: 0.85em;
    font-weight: bold;
}

.assignment-status.primary {
    background: #007bff;
    color: white;
}

.assignment-status.metareview {
    background: #6f42c1;
    color: white;
}

.assignment-status.conflict {
    background: #dc3545;
    color: white;
}

.assigned-indicator {
    background: #28a745;
    color: white;
    padding: 0.2rem 0.4rem;
    border-radius: 50%;
    font-size: 0.8em;
    font-weight: bold;
    margin-right: 0.5rem;
}

.recommended-indicator {
    color: #ffc107;
    font-size: 1.1em;
    margin-right: 0.5rem;
}

.info-panel {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 0.5rem;
    padding: 0.75rem;
}

.no-recommendation-info {
    color: #6c757d;
    font-style: italic;
}

.trackchair-badge {
    background: #6f42c1;
    color: white;
    padding: 0.2rem 0.4rem;
    border-radius: 0.25rem;
    font-size: 0.7em;
    font-weight: bold;
    margin-right: 0.5rem;
}

.auto-assign-indicator {
    margin-right: 0.5rem;
    font-size: 1.1em;
}

.auto-assignment-panel {
    background: #e8f5e8;
    border: 1px solid #c3e6cb;
    border-radius: 0.5rem;
    padding: 1rem;
}

.auto-assign-info {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.info-icon {
    font-size: 1.2em;
}

.conflicted-reviewer { opacity: 0.6; background-color: #fff5f5; }
.conflicted-reviewer .pctbname { color: #dc3545; }
.btn-outline { 
    border: 1px solid #6c757d; 
    background: transparent; 
    color: #6c757d; 
    padding: 0.375rem 0.75rem; 
    border-radius: 0.25rem; 
}
.btn-outline:hover { 
    background: #6c757d; 
    color: white; 
}
#reviewer-search { 
    padding: 0.5rem; 
    border: 1px solid #ced4da; 
    border-radius: 0.25rem; 
    margin-bottom: 1rem;
    width: 100%;
}
.search-no-match { display: none !important; }
.mb-3 { margin-bottom: 1rem; }
.ml-3 { margin-left: 1rem; }
.ml-2 { margin-left: 0.5rem; }
.review-round-selector { 
    display: flex; 
    align-items: center; 
    gap: 0.5rem;
}
.review-round-selector label {
    margin: 0;
    font-weight: bold;
}
.review-round-selector select {
    padding: 0.25rem 0.5rem;
    border: 1px solid #ced4da;
    border-radius: 0.25rem;
    background: white;
    font-size: 0.9em;
}
.track-info {
    background: #e9ecef;
    padding: 0.5rem;
    border-radius: 0.25rem;
    margin-bottom: 1rem;
    font-size: 0.9em;
}
</style>

<script>
(function() {
    "use strict";
    
    var reviewerData = window.reviewerData || {};
    var currentExcludeConflicts = true;
    var currentRound = "R1";
    
    // Initialize the page
    function init() {
        console.log("=== Auto-assignment system initialized ===");
        bindEventListeners();
        updateConflictDisplay();
        showTrackInfo();
        
        // Set up auto-assignment form change detection
        setTimeout(function() {
            setupAutoAssignmentFormChanges();
        }, 100);
    }
    
    // Set up form change detection for auto-assigned reviewers
    function setupAutoAssignmentFormChanges() {
        console.log("Setting up auto-assignment form changes");
        
        var autoElements = document.querySelectorAll(".ctelt.auto-assigned");
        console.log("Found", autoElements.length, "auto-assigned elements");
        
        if (autoElements.length === 0) {
            return;
        }
        
        // Find the main form
        var form = document.getElementById("f-pc-assignments");
        if (!form) {
            console.log("Form not found");
            return;
        }
        
        // For each auto-assigned element, expand it and trigger change detection
        autoElements.forEach(function(element, index) {
            var paperId = element.getAttribute("data-pid");
            var userId = element.getAttribute("data-uid");
            var reviewType = element.getAttribute("data-review-type");
            
            console.log("Processing auto-assigned reviewer", index, "- Paper:", paperId, "User:", userId, "ReviewType:", reviewType);
            
            // Create hidden input to represent the assignment
            var inputName = "assrev" + paperId + "u" + userId;
            var existingInput = form.querySelector("input[name=\"" + inputName + "\"]");
            
            if (!existingInput && reviewType === "4") {
                console.log("Creating hidden input for", inputName);
                
                var hiddenInput = document.createElement("input");
                hiddenInput.type = "hidden";
                hiddenInput.name = inputName;
                hiddenInput.value = "4"; // Primary reviewer
                hiddenInput.setAttribute("data-auto-assigned", "true");
                form.appendChild(hiddenInput);
                
                // Trigger change detection
                if (window.hotcrp && window.hotcrp.check_form_differs) {
                    window.hotcrp.check_form_differs(form);
                } else if (window.check_form_differs) {
                    window.check_form_differs(form);
                }
                
                // Also try jQuery approach
                if (window.jQuery) {
                    window.jQuery(form).trigger("change");
                }
                
                console.log("Hidden input created and change triggered for", inputName);
            }
        });
        
        // Force form to be marked as different
        setTimeout(function() {
            // Try multiple methods to mark form as changed
            if (form.classList) {
                form.classList.add("differs");
                form.classList.remove("differs-ignore");
            }
            
            // Show save button alert
            var paperAlert = document.querySelector(".paper-alert");
            if (paperAlert && paperAlert.classList) {
                paperAlert.classList.remove("hidden");
            }
            
            console.log("Form marked as changed, auto-assignments should be ready to save");
        }, 200);
    }
    
    // Bind event listeners for recommendations
    function bindEventListeners() {
        var excludeCheckbox = document.getElementById("exclude-conflicts");
        if (excludeCheckbox) {
            excludeCheckbox.addEventListener("change", function() {
                currentExcludeConflicts = this.checked;
                updateConflictDisplay();
            });
        }
        
        var roundSelect = document.getElementById("review-round-select");
        if (roundSelect) {
            roundSelect.addEventListener("change", function() {
                currentRound = this.value;
            });
        }
        
        // No view toggle buttons needed in unified view
        
        // Search functionality
        var searchInput = document.getElementById("reviewer-search");
        if (searchInput) {
            searchInput.addEventListener("input", function() {
                filterReviewersBySearch(this.value);
            });
        }
    }
    
    function updateConflictDisplay() {
        var conflictedElements = document.querySelectorAll(".conflicted-reviewer");
        for (var i = 0; i < conflictedElements.length; i++) {
            var element = conflictedElements[i];
            if (currentExcludeConflicts) {
                element.style.display = "none";
            } else {
                element.style.display = "";
            }
        }
    }
    
    function showTrackInfo() {
        var autoAssignedCount = document.querySelectorAll(".auto-assigned").length;
        if (autoAssignedCount > 0) {
            console.log("Found " + autoAssignedCount + " auto-recommended reviewers (shown with 🎯 icon)");
            console.log("These reviewers will be automatically set as Primary reviewers when you save");
        }
    }
    
    // Filter reviewers based on search input
    function filterReviewersBySearch(searchTerm) {
        var reviewerList = document.querySelector("#unified-reviewer-view .reviewer-list-body");
        if (!reviewerList) return;
        
        var reviewers = reviewerList.querySelectorAll(".reviewer-list-row");
        var searchLower = searchTerm.toLowerCase();
        
        reviewers.forEach(function(reviewer) {
            var nameElement = reviewer.querySelector(".reviewer-name-col");
            var visible = true;
            
            if (searchTerm.trim() !== "" && nameElement) {
                var reviewerText = nameElement.textContent.toLowerCase();
                visible = reviewerText.indexOf(searchLower) !== -1;
            }
            
            if (visible) {
                reviewer.classList.remove("search-no-match");
            } else {
                reviewer.classList.add("search-no-match");
            }
        });
    }
    
    // Initialize when DOM is ready
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
    
})();
</script>';
    }

    function print() {
        $prow = $this->prow;
        $user = $this->user;
        $this->pt = new PaperTable($user, $this->qreq, $prow);
        $this->pt->resolve_review(false);
        $this->allow_view_authors = $user->allow_view_authors($prow);
        PaperTable::print_header($this->pt, $this->qreq);

        // begin form and table
        $this->pt->print_paper_info();

        // reviewer information
        $t = $this->pt->review_table();
        if ($t !== "") {
            echo '<div class="pcard revcard">',
                '<h2 class="revcard-head" id="current-reviews">Current reviews</h2>',
                '<div class="review-legend mb-3" style="background: #f8f9fa; padding: 8px 12px; border-radius: 4px; border-left: 3px solid #dee2e6;">',
                '<small class="text-muted">',
                '<strong>Icon Legend:</strong><br>',
                '<span class="legend-item" style="margin-right: 15px; display: inline-block;">📧 <strong>Yellow envelope</strong> - Reviewer assigned but not yet notified</span>',
                '<span class="legend-item" style="margin-right: 15px; display: inline-block;">✓ <strong>Green checkmark</strong> - Reviewer has been notified</span>',
                '</small>',
                '</div>',
                '<div class="revpcard-body">', $t, '</div></div>';
        }

        // requested reviews
        $requests = [];
        foreach ($this->pt->all_reviews() as $rrow) {
            if ($rrow->reviewType < REVIEW_SECONDARY
                && $rrow->reviewStatus < ReviewInfo::RS_DRAFTED
                && $user->can_view_review_identity($prow, $rrow)
                && ($user->can_administer($prow) || $rrow->requestedBy == $user->contactId)) {
                $requests[] = [0, max((int) $rrow->timeRequestNotified, (int) $rrow->timeRequested), count($requests), $rrow];
            }
        }
        foreach ($prow->review_requests() as $rrow) {
            if ($user->can_view_review_identity($prow, $rrow)) {
                $requests[] = [1, (int) $rrow->timeRequested, count($requests), $rrow];
            }
        }
        foreach ($prow->review_refusals() as $rrow) {
            if ($user->can_view_review_identity($prow, $rrow)) {
                $requests[] = [2, (int) $rrow->timeRefused, count($requests), $rrow];
            }
        }
        usort($requests, function ($a, $b) {
            return $a[0] <=> $b[0] ? : ($a[1] <=> $b[1] ? : $a[2] <=> $b[2]);
        });

        if (!empty($requests)) {
            echo '<div class="pcard revcard">',
                '<h2 class="revcard-head" id="review-requests">Review requests</h2>',
                '<div class="revcard-body"><div class="ctable-wide">';
            foreach ($requests as $req) {
                $this->print_reqrev($req[3], $req[1]);
            }
            echo '</div></div></div>';
        }

        // PC assignments
        if ($user->can_administer($prow)) {
            // Load global assignment counts (bypass permission restrictions)
            $acs = $this->load_global_assignment_counts($user);
            
            // Load track-specific assignment count set
            $paper_track = $this->get_paper_track_tag($prow);
            $track_acs = null;
            if ($paper_track) {
                $track_acs = $this->load_track_assignment_counts($user, $paper_track);
            }

            // Only recommend reviewers if no PRIMARY assignments exist yet
            // Meta reviewers don't prevent primary reviewer recommendations
            $has_existing_primary_assignments = false;
            foreach ($prow->all_reviews() as $rrow) {
                if ($rrow->reviewType == REVIEW_PRIMARY || $rrow->reviewType == REVIEW_SECONDARY) {
                    $has_existing_primary_assignments = true;
                    break;
                }
            }
            
            if ($has_existing_primary_assignments) {
                // If primary assignments already exist, don't show recommendations
                // But still filter out conflicted PC members AND apply track filtering
                $filtered_pc = [];
                $paper_track = $this->get_paper_track_tag($prow);
                
                foreach ($this->conf->pc_members() as $pc) {
                    // Include track chairs for this paper's track
                    $is_trackchair = $this->is_track_chair($pc, $paper_track);
                    
                    // Skip PC Chair only if they are not also a track chair
                    if ($pc->privChair && !$is_trackchair) {
                        continue;
                    }
                    
                    // Check if PC member is assignable to this track
                    if (!$pc->pc_track_assignable($prow) && !$prow->has_reviewer($pc) && !$is_trackchair) {
                        continue;
                    }
                    
                    // Filter by track membership if paper has a track, but always include track chairs
                    if ($paper_track && !$this->is_track_member($pc, $paper_track) && !$is_trackchair) {
                        continue;
                    }
                    
                    $conflict_type = $prow->conflict_type($pc);
                    $is_conflicted = Conflict::is_conflicted($conflict_type) || Conflict::is_author($conflict_type);
                    $has_potential_conflict = $prow->potential_conflict($pc);
                    
                    // Skip conflicted reviewers and those with potential conflicts
                    if ($is_conflicted || $has_potential_conflict) {
                        continue;
                    }
                    
                    $filtered_pc[] = $pc;
                }
                
                $recommended_data = [
                    'recommended' => [],
                    'all' => $filtered_pc,
                    'scores' => []
                ];
            } else {
                // Get recommended reviewers data (limit to 3) even if meta reviewers exist
                // This ensures primary reviewer recommendations are shown even after meta reviewer assignment
                $recommended_data = $this->get_recommended_reviewers(3, true, $acs);
            }
            
            // Get paper track for JavaScript
            $paper_track = $this->get_paper_track_tag($prow);

            // PC conflicts row
            echo '<div class="pcard revcard">',
                '<h2 class="revcard-head" id="pc-assignments">PC assignments</h2>',
                '<div class="revcard-body">',
                Ht::form($this->conf->hoturl("=assign", "p=$prow->paperId"), [
                    "id" => "f-pc-assignments",
                    "class" => "need-unload-protection need-diff-check",
                    "data-differs-toggle" => "paper-alert"
                ]);
            Ht::stash_script('$(hotcrp.load_editable_pc_assignments)');

            if ($this->conf->has_topics()) {
                echo "<p>the higher the topic scores , the more interested the reviewer is in the topic.</p>";
            } else {
                echo "<p>Review preferences display as \"P#\",the higher the P, the more interested the reviewer is in the topic.</p>";
            }

            // Add explanation for table headers
            echo '<div class="table-headers-explanation mb-3" style="background: #f8f9fa; padding: 12px; border-radius: 6px; border-left: 4px solid #007bff;">',
                '<h4 style="margin: 0 0 8px 0; font-size: 1em; color: #495057;">Column Explanations:</h4>',
                '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; font-size: 0.9em; color: #6c757d;">',
                '<div>',
                '<strong>Topic Score:</strong> Numerical score indicating how well the reviewer\'s research interests match this paper\'s topics. Higher scores indicate better topic alignment.',
                '</div>',
                '<div>',
                '<strong>Global Reviews:</strong> Total number of reviews this reviewer has been assigned across all tracks in the conference. Used to assess overall workload.',
                '</div>',
                '<div>',
                '<strong>Track Reviews:</strong> Number of reviews this reviewer has been assigned specifically within this paper\'s track. Indicates track-specific experience.',
                '</div>',
                '<div>',
                '<strong>Assignment:</strong> Current review assignment status for this paper. Options include None, Primary, Metareview, or Conflict.',
                '</div>',
                '</div>',
                '</div>';

            // Embed reviewer data and paper track for JavaScript
            echo '<script>window.reviewerData = ', json_encode($recommended_data['scores']), ';</script>';
            echo '<script>window.paperTrack = ', json_encode($paper_track), ';</script>';

            echo '<div class="pc-ctable-container" ',
                'data-review-rounds="', htmlspecialchars(json_encode(array_keys($this->conf->round_selector_options(false)))), '"',
                ' data-default-review-round="', htmlspecialchars($this->conf->assignment_round_option(false)), '">';

            $this->conf->ensure_cached_user_collaborators();
            
            // Print unified reviewers section
            $this->print_all_reviewers_unified($recommended_data, $acs, $track_acs);

            echo "</div>\n",
                '<div class="aab">',
                '<div class="aabut">', Ht::submit("update", "Save assignments", ["class" => "btn-primary"]), '</div>',
                '<div class="aabut">', Ht::submit("cancel", "Cancel"), '</div>',
                '<div id="assresult" class="aabut"></div>',
                '</div></form></div></div>';
        }


        // add external reviewers
        $req = "Request external review";
        if (!$user->allow_administer($prow) && $this->conf->setting("extrev_chairreq")) {
            $req = "Propose external review";
        }
        echo '<div class="pcard revcard">',
            Ht::form($this->conf->hoturl("=assign", "p={$prow->paperId}"), ["novalidate" => true]),
            "<h2 class=\"revcard-head\" id=\"external-reviews\">", $req, "</h2><div class=\"revcard-body\">";

        echo '<p class="w-text">', $this->conf->_i("external_review_request_description");
        if ($user->allow_administer($prow)) {
            echo "\nTo create an anonymous review with a review token, leave Name and Email blank.";
        }
        echo '</p>';

        if (($rrow = $prow->review_by_user($this->user))
            && $rrow->reviewType == REVIEW_SECONDARY
            && ($round_name = $this->conf->round_name($rrow->reviewRound))) {
            echo Ht::hidden("round", $round_name);
        }
        $email_class = "fullw";
        if ($this->user->can_lookup_user()) {
            $email_class .= " uii js-email-populate";
            if ($this->allow_view_authors) {
                $email_class .= " want-potential-conflict";
            }
        }
        echo '<div class="w-text g">',
            '<div class="', $this->ms->control_class("email", "f-i"), '">',
            Ht::label("Email", "revreq_email"),
            $this->ms->feedback_html_at("email"),
            Ht::entry("email", (string) $this->qreq->email, ["id" => "revreq_email", "size" => 52, "class" => $email_class, "autocomplete" => "off", "type" => "email"]),
            '</div>',
            '<div class="f-mcol">',
            '<div class="', $this->ms->control_class("given_name", "f-i"), '">',
            Ht::label("First name (given name)", "revreq_given_name"),
            $this->ms->feedback_html_at("given_name"),
            Ht::entry("given_name", (string) $this->qreq->given_name, ["id" => "revreq_given_name", "size" => 24, "class" => "fullw", "autocomplete" => "off"]),
            '</div><div class="', $this->ms->control_class("family_name", "f-i"), '">',
            Ht::label("Last name (family name)", "revreq_family_name"),
            $this->ms->feedback_html_at("family_name"),
            Ht::entry("family_name", (string) $this->qreq->family_name, ["id" => "revreq_family_name", "size" => 24, "class" => "fullw", "autocomplete" => "off"]),
            '</div></div>',
            '<div class="', $this->ms->control_class("affiliation", "f-i"), '">',
            Ht::label("Affiliation", "revreq_affiliation"),
            $this->ms->feedback_html_at("affiliation"),
            Ht::entry("affiliation", (string) $this->qreq->affiliation, ["id" => "revreq_affiliation", "size" => 52, "class" => "fullw", "autocomplete" => "off"]),
            '</div>';
        if ($this->allow_view_authors) {
            echo '<div class="potential-conflict-container hidden f-i"><label>Potential conflict</label><div class="potential-conflict"></div></div>';
        }

        // reason area
        $null_mailer = new HotCRPMailer($this->conf);
        $reqbody = $null_mailer->expand_template("requestreview");
        if ($reqbody && strpos($reqbody["body"], "REASON") !== false) {
            echo '<div class="f-i">',
                Ht::label('Note to reviewer <span class="n">(optional)</span>', "revreq_reason"),
                Ht::textarea("reason", $this->qreq->reason,
                        ["class" => "need-autogrow fullw", "rows" => 2, "cols" => 60, "spellcheck" => "true", "id" => "revreq_reason"]),
                "</div>\n\n";
        }

        if ($user->can_administer($prow)) {
            echo '<label class="', $this->ms->control_class("override", "checki"), '"><span class="checkc">',
                Ht::checkbox("override"),
                ' </span>Override declined requests</label>';
        }

        echo '<div class="aab">',
            '<div class="aabut aabutsp">', Ht::submit("requestreview", "Request review", ["class" => "btn-primary"]), '</div>',
            '<div class="aabut"><button type="button" class="link ulh ui js-request-review-preview-email">Preview request email</button></div>',
            "</div>\n\n";

        echo "</div></div></form></div></article>\n";

        $this->qreq->print_footer();
    }

    static function go(Contact $user, Qrequest $qreq) {
        if (!$user->email) {
            $user->escape();
        }
        $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $ap = new Assign_Page($user, $qreq);
        $ap->assign_load();
        $ap->handle_request();
        $ap->print();
    }

    /** @return array<string,string> */
    private function build_track_name_mapping() {
        // Track display names mapping - can be maintained here or loaded from config
        $track_display_names = [
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
            'spmus' => 'Security and Privacy in Mobile and Ubiquitous Systems',
        ];

        return $track_display_names;
    }

    /** @param string $track_tag @return string */
    private function get_track_display_name($track_tag) {
        $track_mapping = $this->build_track_name_mapping();
        return $track_mapping[$track_tag] ?? $track_tag;
    }

    /**
     * Load assignment counts for a specific track
     * @param Contact $user
     * @param string $track_tag
     * @return AssignmentCountSet
     */
    private function load_track_assignment_counts(Contact $user, $track_tag) {
        $acs = new AssignmentCountSet($user);
        $acs->has = AssignmentCountSet::HAS_REVIEW;
        
        // Get all papers with the specific track tag
        $search = new PaperSearch($user, "#{$track_tag}");
        $paper_ids = $search->paper_ids();
        
        if (empty($paper_ids)) {
            return $acs;
        }
        
        // Query reviews for papers in this track
        $paper_ids_str = join(",", $paper_ids);
        $result = $user->conf->qe("select r.contactId, group_concat(r.reviewType separator '')
                from PaperReview r
                join Paper p on (p.paperId=r.paperId)
                where r.reviewType>=" . REVIEW_PC . " 
                and (r.reviewSubmitted>0 or r.timeApprovalRequested!=0 or p.timeSubmitted>0)
                and p.paperId in ({$paper_ids_str})
                group by r.contactId");
                
        while (($row = $result->fetch_row())) {
            $ct = $acs->ensure((int) $row[0]);
            $ct->rev = strlen($row[1]);
            $ct->meta = substr_count($row[1], (string) REVIEW_META);
            $ct->pri = substr_count($row[1], (string) REVIEW_PRIMARY);
            $ct->sec = substr_count($row[1], (string) REVIEW_SECONDARY);
        }
        Dbl::free($result);
        
        return $acs;
    }

    /**
     * Load global assignment counts without permission restrictions
     * @param Contact $user
     * @return AssignmentCountSet
     */
    private function load_global_assignment_counts(Contact $user) {
        $acs = new AssignmentCountSet($user);
        $acs->has = AssignmentCountSet::HAS_REVIEW;
        
        // Query all reviews globally, bypassing permission restrictions
        $result = $user->conf->qe("select r.contactId, group_concat(r.reviewType separator '')
                from PaperReview r
                join Paper p on (p.paperId=r.paperId)
                where r.reviewType>=" . REVIEW_PC . " 
                and (r.reviewSubmitted>0 or r.timeApprovalRequested!=0 or p.timeSubmitted>0)
                group by r.contactId");
                
        while (($row = $result->fetch_row())) {
            $ct = $acs->ensure((int) $row[0]);
            $ct->rev = strlen($row[1]);
            $ct->meta = substr_count($row[1], (string) REVIEW_META);
            $ct->pri = substr_count($row[1], (string) REVIEW_PRIMARY);
            $ct->sec = substr_count($row[1], (string) REVIEW_SECONDARY);
        }
        Dbl::free($result);
        
        return $acs;
    }
}

