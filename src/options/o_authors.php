<?php
// o_authors.php -- HotCRP helper class for authors intrinsic
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Authors_PaperOption extends PaperOption {
    /** @var int */
    private $max_count;
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->max_count = $args->max ?? 0;
    }
    function author_list(PaperValue $ov) {
        return PaperInfo::parse_author_list($ov->data() ?? "");
    }
    function value_force(PaperValue $ov) {
        $ov->set_value_data([1], [$ov->prow->authorInformation]);
    }
    function value_export_json(PaperValue $ov, PaperExport $pex) {
        $contacts_ov = $ov->prow->option(PaperOption::CONTACTSID);
        $lemails = [];
        foreach ($contacts_ov->data_list() as $email) {
            $lemails[] = strtolower($email);
        }
        $au = [];
        foreach (PaperInfo::parse_author_list($ov->data() ?? "") as $auth) {
            $au[] = $j = (object) $auth->unparse_nea_json();
            if ($auth->email !== "" && in_array(strtolower($auth->email), $lemails)) {
                $j->contact = true;
            }
        }
        return $au;
    }

    function value_check(PaperValue $ov, Contact $user) {
        $aulist = $this->author_list($ov);
        $nreal = 0;
        $lemails = [];
        foreach ($aulist as $auth) {
            $nreal += $auth->is_empty() ? 0 : 1;
            $lemails[] = strtolower($auth->email);
        }
        if ($nreal === 0) {
            if (!$ov->prow->allow_absent()) {
                $ov->estop($this->conf->_("<0>Entry required"));
                $ov->append_item(MessageItem::error_at("authors:1"));
            }
            return;
        }
        if ($this->max_count > 0 && $nreal > $this->max_count) {
            $ov->estop($this->conf->_("<0>A {submission} may have at most {max} authors", new FmtArg("max", $this->max_count)));
        }

        $req_orcid = $this->conf->opt("requireOrcid") ?? 0;
        if ($req_orcid === 2
            && ($ov->prow->outcome_sign <= 0
                || !$ov->prow->can_author_view_decision())) {
            $req_orcid = 0;
        }
        $msg_bademail = $msg_missing = $msg_dupemail = false;
        $msg_missing_affiliation = $msg_missing_country = false;
        $msg_orcid = [];
        $n = 0;
        foreach ($aulist as $auth) {
            ++$n;
            if ($auth->is_empty()) {
                continue;
            }
            if ($auth->firstName === ""
                && $auth->lastName === ""
                && $auth->email === ""
                && $auth->affiliation !== "") {
                $msg_missing = true;
                $ov->append_item(MessageItem::warning_at("authors:{$n}"));
                continue;
            }
            if (strpos($auth->email, "@") === false
                && strpos($auth->affiliation, "@") !== false) {
                $msg_bademail = true;
                $ov->append_item(MessageItem::warning_at("authors:{$n}"));
            }
            if ($auth->email !== ""
                && !validate_email($auth->email)
                && !$ov->prow->author_by_email($auth->email)) {
                $ov->estop(null);
                $ov->append_item(MessageItem::estop_at("authors:{$n}", "<0>Invalid email address '{$auth->email}'"));
                continue;
            }
            // Check for required affiliation field
            if ($auth->affiliation === "") {
                $msg_missing_affiliation = true;
                $ov->estop(null);
                $ov->append_item(MessageItem::estop_at("authors:{$n}:affiliation", "<0>Affiliation is required for each author"));
            }
            // Check for required country field
            if ($auth->country === "") {
                $msg_missing_country = true;
                $ov->estop(null);
                $ov->append_item(MessageItem::estop_at("authors:{$n}:country", "<0>Country is required for each author"));
            }
            if ($req_orcid > 0) {
                if ($auth->email === "") {
                    $msg_missing = true;
                    $ov->append_item(MessageItem::warning_at("authors:{$n}:email"));
                } else if (!($u = $this->conf->user_by_email($auth->email))
                           || !$u->confirmed_orcid()) {
                    $msg_orcid[] = $auth->email;
                    $ov->append_item(MessageItem::warning_at("authors:{$n}"));
                }
            }
            if ($auth->email !== ""
                && ($n2 = array_search(strtolower($auth->email), $lemails)) !== $n - 1) {
                $msg_dupemail = true;
                $ov->append_item(MessageItem::warning_at("authors:{$n}:email"));
                $ov->append_item(MessageItem::warning_at("authors:" . ($n2 + 1) . ":email"));
            }
        }

        if ($msg_missing) {
            if ($req_orcid > 0) {
                $ov->warning("<0>Please enter a name and email address for every author");
            } else {
                $ov->warning("<0>Please enter a name and optional email address for every author");
            }
        }
        if ($msg_bademail) {
            $ov->warning("<0>You may have entered an email address in the wrong place. The first author field is for email, the second for name, and the third for affiliation");
        }
        if ($msg_dupemail) {
            $ov->warning("<0>The same email address has been used for different authors. This is usually an error");
        }
        if ($msg_orcid) {
            $ov->warning($this->conf->_("<5>Some authors have not configured their <a href=\"https://orcid.org\">ORCID iDs</a>"));
            $ov->inform($this->conf->_("<0>This site requests that authors provide ORCID iDs. Please ask {0:list} to sign in and update their profiles.", new FmtArg(0, $msg_orcid, 0)));
        }
    }

    function value_save(PaperValue $ov, PaperStatus $ps) {
        // Check if we're in final phase and restrict changes
        $is_final_phase = $ov->prow->phase() === PaperInfo::PHASE_FINAL;
        $can_admin = $ps->user->can_administer($ov->prow);
        
        if ($is_final_phase && !$can_admin) {
            // In final phase, only allow changes to affiliation and country
            $new_authlist = $this->author_list($ov);
            $old_authlist = $this->author_list($ov->prow->base_option($this->id));
            
            // Check if the number of authors changed (order change)
            if (count($new_authlist) !== count($old_authlist)) {
                $ov->estop("Author list length cannot be changed in final phase");
                return false;
            }
            
            // Check each author for forbidden changes
            foreach ($new_authlist as $i => $new_auth) {
                $old_auth = $old_authlist[$i] ?? new Author;
                
                // Check if name changed
                if ($new_auth->name(NAME_PARSABLE) !== $old_auth->name(NAME_PARSABLE)) {
                    $ov->estop("Author names cannot be changed in final phase");
                    return false;
                }
                
                // Check if email changed
                if ($new_auth->email !== $old_auth->email) {
                    $ov->estop("Author emails cannot be changed in final phase");
                    return false;
                }
                
                // Affiliation and country changes are allowed - no validation needed
            }
            
            // Check if author order changed by comparing emails in sequence
            $old_emails = array_map(function($a) { return $a->email; }, $old_authlist);
            $new_emails = array_map(function($a) { return $a->email; }, $new_authlist);
            if ($old_emails !== $new_emails) {
                $ov->estop("Author order cannot be changed in final phase");
                return false;
            }
        }
        
        // construct property
        $authlist = $this->author_list($ov);
        $d = "";
        foreach ($authlist as $auth) {
            if (!$auth->is_empty()) {
                $d .= ($d === "" ? "" : "\n") . $auth->unparse_tabbed();
            }
        }
        // apply change
        if ($d !== $ov->prow->base_option($this->id)->data()) {
            $ps->change_at($this);
            $ov->prow->set_prop("authorInformation", $d);
            $this->value_save_conflict_values($ov, $ps);
        }
        return true;
    }
    function value_save_conflict_values(PaperValue $ov, PaperStatus $ps) {
        $ps->clear_conflict_values(CONFLICT_AUTHOR);
        foreach ($this->author_list($ov) as $i => $auth) {
            if ($auth->email !== "") {
                $cflags = CONFLICT_AUTHOR
                    | ($ov->anno("contact:{$auth->email}") ? CONFLICT_CONTACTAUTHOR : 0);
                $ps->update_conflict_value($auth, $cflags, $cflags);
            }
        }
        $ps->checkpoint_conflict_values();
    }
    static private function expand_author(Author $au, PaperInfo $prow) {
        if ($au->email !== ""
            && ($aux = $prow->author_by_email($au->email))) {
            if ($au->firstName === "" && $au->lastName === "") {
                $au->firstName = $aux->firstName;
                $au->lastName = $aux->lastName;
            }
            if ($au->affiliation === "") {
                $au->affiliation = $aux->affiliation;
            }
            if ($au->country === "" && property_exists($aux, 'country')) {
                $au->country = $aux->country ?? "";
            }
        }
        // Also try to get country from user account if email is provided
        if ($au->email !== "" && $au->country === "") {
            if (($u = $prow->conf->user_by_email($au->email))) {
                $au->country = $u->country_code();
            }
        }
    }
    function parse_qreq(PaperInfo $prow, Qrequest $qreq) {
        $v = [];
        $auth = new Author;
        for ($n = 1; true; ++$n) {
            $email = $qreq["authors:{$n}:email"];
            $name = $qreq["authors:{$n}:name"];
            $aff = $qreq["authors:{$n}:affiliation"];
            $country = $qreq["authors:{$n}:country"];
            if ($email === null && $name === null && $aff === null && $country === null) {
                break;
            }
            $auth->email = $auth->firstName = $auth->lastName = $auth->affiliation = $auth->country = "";
            $name = simplify_whitespace($name ?? "");
            if ($name !== "" && $name !== "Name") {
                list($auth->firstName, $auth->lastName, $auth->email) = Text::split_name($name, true);
            }
            $email = simplify_whitespace($email ?? "");
            if ($email !== "" && $email !== "Email") {
                $auth->email = $email;
            }
            $aff = simplify_whitespace($aff ?? "");
            if ($aff !== "" && $aff !== "Affiliation") {
                $auth->affiliation = $aff;
            }
            $country = simplify_whitespace($country ?? "");
            if ($country !== "") {
                $auth->country = $country;
            }
            // some people enter email in the affiliation slot
            if (strpos($aff, "@") !== false
                && validate_email($aff)
                && !validate_email($auth->email)) {
                $auth->affiliation = $auth->email;
                $auth->email = $aff;
            }
            self::expand_author($auth, $prow);
            $v[] = $auth->unparse_tabbed();
        }
        return PaperValue::make($prow, $this, 1, join("\n", $v));
    }
    function parse_json(PaperInfo $prow, $j) {
        if (!is_array($j) || is_associative_array($j)) {
            return PaperValue::make_estop($prow, $this, "<0>Validation error");
        }
        $v = $cemail = [];
        foreach ($j as $i => $auj) {
            if (is_object($auj) || is_associative_array($auj)) {
                $auth = Author::make_keyed($auj);
                $contact = $auj->contact ?? null;
            } else if (is_string($auj)) {
                $auth = Author::make_string($auj);
                $contact = null;
            } else {
                return PaperValue::make_estop($prow, $this, "<0>Validation error on author #" . ($i + 1));
            }
            self::expand_author($auth, $prow);
            $v[] = $auth->unparse_tabbed();
            if ($contact && $auth->email !== "") {
                $cemail[] = $auth->email;
            }
        }
        $ov = PaperValue::make($prow, $this, 1, join("\n", $v));
        foreach ($cemail as $email) {
            $ov->set_anno("contact:{$email}", true);
        }
        return $ov;
    }

    private function editable_author_component_entry($pt, $n, $component, $au, $reqau, $ignore_diff) {
        // Check if we're in final phase and restrict editing
        $is_final_phase = $pt->prow->phase() === PaperInfo::PHASE_FINAL;
        
        if ($component === "name") {
            $js = ["size" => "35", "placeholder" => "Name", "autocomplete" => "off", "aria-label" => "Author name"];
            $auval = $au ? $au->name(NAME_PARSABLE) : "";
            $val = $reqau ? $reqau->name(NAME_PARSABLE) : "";
            
            // In final phase, make name field readonly
            if ($is_final_phase && !$pt->user->can_administer($pt->prow)) {
                $js["readonly"] = true;
                $js["title"] = "Author names cannot be modified in final phase";
                $js["class"] = ($js["class"] ?? "") . " readonly-final-phase";
            }
        } else if ($component === "email") {
            $js = ["size" => "30", "placeholder" => "Email", "autocomplete" => "off", "aria-label" => "Author email"];
            $auval = $au ? $au->email : "";
            $val = $reqau ? $reqau->email : "";
            
            // In final phase, make email field readonly
            if ($is_final_phase && !$pt->user->can_administer($pt->prow)) {
                $js["readonly"] = true;
                $js["title"] = "Author emails cannot be modified in final phase";
                $js["class"] = ($js["class"] ?? "") . " readonly-final-phase";
            }
        } else if ($component === "affiliation") {
            $js = ["size" => "32", "placeholder" => "Affiliation", "autocomplete" => "off", "aria-label" => "Author affiliation"];
            $auval = $au ? $au->affiliation : "";
            $val = $reqau ? $reqau->affiliation : "";
            // Affiliation remains editable in final phase
        } else if ($component === "country") {
            // Country field uses a selector instead of text input
            $auval = $au ? $au->country : "";
            $val = $reqau ? $reqau->country : "";
            $country = $val !== "" ? $val : $auval;
            $country = Countries::fix($country);
            
            $extra = [
                "id" => "authors:{$n}:country",
                "class" => $pt->max_control_class(["authors:{$n}", "authors:{$n}:country"], "js-autosubmit editable-author editable-author-country" . ($ignore_diff ? " ignore-diff" : "")),
                "autocomplete" => "country",
                "aria-label" => "Author country"
            ];
            if ($val !== $auval) {
                $extra["data-default-value"] = $auval;
            }
            // Country remains editable in final phase
            return Countries::selector("authors:{$n}:country", $country, $extra);
        } else {
            $js = ["size" => "32", "placeholder" => "Affiliation", "autocomplete" => "off", "aria-label" => "Author affiliation"];
            $auval = $au ? $au->affiliation : "";
            $val = $reqau ? $reqau->affiliation : "";
        }

        $js["class"] = $pt->max_control_class(["authors:{$n}", "authors:{$n}:{$component}"], "need-autogrow js-autosubmit editable-author editable-author-{$component}" . ($ignore_diff ? " ignore-diff" : ""));
        if ($component === "email" && $pt->user->can_lookup_user()) {
            $js["class"] .= " uii js-email-populate";
        }
        if ($val !== $auval) {
            $js["data-default-value"] = $auval;
            if ($component !== "email" && $pt->prow->is_new()) {
                $js["data-populated-value"] = $val;
            }
        }
        return Ht::entry("authors:{$n}:{$component}", $val, $js);
    }
    private function echo_editable_authors_line($pt, $n, $au, $reqau, $shownum) {
        // on new paper, default to editing user as first author
        $ignore_diff = false;
        if ($n === 1
            && !$au
            && !$pt->user->can_administer($pt->prow)
            && (!$reqau || $reqau->nea_equals($pt->user->populated_user()))) {
            $reqau = new Author($pt->user->populated_user());
            $ignore_diff = true;
        }

        // Check if we're in final phase to disable reordering
        $is_final_phase = $pt->prow->phase() === PaperInfo::PHASE_FINAL;
        $can_admin = $pt->user->can_administer($pt->prow);
        
        // Disable dragging in final phase for non-admins
        $div_classes = "author-entry d-flex";
        if (!$is_final_phase || $can_admin) {
            $div_classes .= " draggable";
        }
        
        echo '<div class="' . $div_classes . '">';
        if ($shownum) {
            if (!$is_final_phase || $can_admin) {
                // Show drag handle only if not in final phase or user is admin
                echo '<div class="flex-grow-0"><button type="button" class="draghandle ui js-dropmenu-open ui-drag row-order-draghandle need-tooltip need-dropmenu" draggable="true" title="Click or drag to reorder" data-tooltip-anchor="e">&zwnj;</button></div>';
            } else {
                // Show disabled drag handle with explanation
                echo '<div class="flex-grow-0"><span class="draghandle-disabled need-tooltip" title="Author order cannot be changed in final phase" data-tooltip-anchor="e">&zwnj;</span></div>';
            }
            echo '<div class="flex-grow-0 row-counter">', $n, '.</div>';
        }
        echo '<div class="flex-grow-1">',
            $this->editable_author_component_entry($pt, $n, "email", $au, $reqau, $ignore_diff), ' ',
            $this->editable_author_component_entry($pt, $n, "name", $au, $reqau, $ignore_diff), ' ',
            $this->editable_author_component_entry($pt, $n, "affiliation", $au, $reqau, $ignore_diff), ' ',
            $this->editable_author_component_entry($pt, $n, "country", $au, $reqau, $ignore_diff),
            $pt->messages_at("authors:{$n}"),
            $pt->messages_at("authors:{$n}:email"),
            $pt->messages_at("authors:{$n}:name"),
            $pt->messages_at("authors:{$n}:affiliation"),
            $pt->messages_at("authors:{$n}:country"),
            '</div></div>';
    }
    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $title = $pt->edit_title_html($this);
        $sb = $this->conf->submission_blindness();
        if ($sb !== Conf::BLIND_NEVER
            && $pt->prow->outcome_sign > 0
            && !$this->conf->setting("seedec_hideau")
            && $pt->prow->can_author_view_decision()) {
            $sb = Conf::BLIND_NEVER;
        }
        if ($sb === Conf::BLIND_ALWAYS) {
            $title .= ' <span class="n">(anonymous)</span>';
        } else if ($sb === Conf::BLIND_UNTILREVIEW) {
            $title .= ' <span class="n">(anonymous until review)</span>';
        }
        $pt->print_editable_option_papt($this, $title, [
            "id" => "authors", "for" => false
        ]);

        $min_authors = $this->max_count > 0 ? min(5, $this->max_count) : 5;

        $aulist = $this->author_list($ov);
        $reqaulist = $this->author_list($reqov);
        $nreqau = count($reqaulist);
        while ($nreqau > 0 && $reqaulist[$nreqau-1]->is_empty()) {
            --$nreqau;
        }
        $nau = max($nreqau, count($aulist), $min_authors);
        if (($nau === $nreqau || $nau === count($aulist))
            && ($this->max_count <= 0 || $nau + 1 <= $this->max_count)) {
            ++$nau;
        }
        $ndigits = (int) ceil(log10($nau + 1));

        // Check if we're in final phase to disable row ordering
        $is_final_phase = $pt->prow->phase() === PaperInfo::PHASE_FINAL;
        $can_admin = $pt->user->can_administer($pt->prow);
        
        $container_classes = "need-row-order-autogrow";
        if (!$is_final_phase || $can_admin) {
            $container_classes .= " js-row-order";
        }
        
        echo '<div class="papev">';
        if ($is_final_phase && !$can_admin) {
            echo '<div class="msg msg-warning"><strong>Note:</strong> In the final phase, you can only modify author affiliations and countries. Author names, emails, and order cannot be changed.</div>';
        }
        echo '<div id="authors:container" class="', $container_classes, '" data-min-rows="', $min_authors, '"',
            $this->max_count > 0 ? " data-max-rows=\"{$this->max_count}\"" : "",
            ' data-row-counter-digits="', $ndigits,
            '" data-row-template="authors:row-template">';
        for ($n = 1; $n <= $nau; ++$n) {
            $this->echo_editable_authors_line($pt, $n, $aulist[$n-1] ?? null, $reqaulist[$n-1] ?? null, $this->max_count !== 1);
        }
        echo '</div>';
        echo '<template id="authors:row-template" class="hidden">';
        $this->echo_editable_authors_line($pt, '$', null, null, $this->max_count !== 1);
        echo "</template></div></div>\n\n";
    }

    function field_fmt_context() {
        return [new FmtArg("max", $this->max_count)];
    }

    function render(FieldRender $fr, PaperValue $ov) {
        if ($fr->want(FieldRender::CFPAGE)) {
            $fr->table->render_authors($fr, $this);
        } else {
            $names = ["<ul class=\"x namelist\">"];
            foreach ($this->author_list($ov) as $au) {
                $n = htmlspecialchars(trim("{$au->firstName} {$au->lastName}"));
                if ($au->email !== "") {
                    $ehtml = htmlspecialchars($au->email);
                    $e = "&lt;<a href=\"mailto:{$ehtml}\" class=\"q\">{$ehtml}</a>&gt;";
                } else {
                    $e = "";
                }
                $t = ($n === "" ? $e : $n);
                if ($au->affiliation !== "") {
                    $t .= " <span class=\"auaff\">(" . htmlspecialchars($au->affiliation);
                    if ($au->country !== "") {
                        $t .= ", " . htmlspecialchars(Countries::code_to_name($au->country));
                    }
                    $t .= ")</span>";
                } else if ($au->country !== "") {
                    $t .= " <span class=\"auaff\">(" . htmlspecialchars(Countries::code_to_name($au->country)) . ")</span>";
                }
                if ($n !== "" && $e !== "") {
                    $t .= " " . $e;
                }
                $names[] = "<li class=\"odname\">{$t}</li>";
            }
            $names[] = "</ul>";
            $fr->set_html(join("", $names));
        }
    }

    function jsonSerialize() {
        $j = parent::jsonSerialize();
        if ($this->max_count > 0) {
            $j->max = $this->max_count;
        }
        return $j;
    }
    function export_setting() {
        $sfs = parent::export_setting();
        $sfs->max = $this->max_count;
        return $sfs;
    }
}
