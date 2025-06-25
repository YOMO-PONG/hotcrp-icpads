<?php
// track_membership.php -- HotCRP Track membership selection page
// Copyright (c) 2025 ICPADS; see LICENSE.

require_once("src/initweb.php");

if (!$Me->is_signed_in()) {
    $Me->escape();
}

if (!$Me->isPC && !$Me->privChair) {
    Multiconference::fail($Qreq, 403, ["title" => "Track Membership"], 
        "<5>Permission error: only PC members can manage track membership");
}

if (!$Conf->has_tracks()) {
    Multiconference::fail($Qreq, 404, ["title" => "Track Membership"], 
        "<5>No tracks configured in this conference");
}

// Handle AJAX requests
if ($Qreq->ajax) {
    json_exit(User_API::track_membership($Me, $Qreq));
}

// Handle form submission
if ($Qreq->valid_post() && isset($Qreq->save_track_membership)) {
    $selected_tracks = [];
    $all_tracks = $Conf->all_tracks();
    
    foreach ($all_tracks as $track) {
        if ($track->is_default) continue;
        $field_name = "track_" . $track->tag;
        if (isset($Qreq->$field_name) && $Qreq->$field_name) {
            $selected_tracks[] = $track->tag;
        }
    }
    
    // Use our API function to save
    $temp_qreq = new Qrequest("POST");
    $temp_qreq->tracks = $selected_tracks;
    $result = User_API::track_membership($Me, $temp_qreq);
    
    if ($result->content['ok']) {
        $Conf->success_msg("Track membership updated successfully!");
    } else {
        $Conf->error_msg("Failed to update track membership: " . ($result->content['message'] ?? 'Unknown error'));
    }
}

// Get current user's track memberships
$current_tracks = [];
$user_tags = $Me->viewable_tags($Me);
if ($user_tags) {
    foreach (explode(" ", $user_tags) as $tag) {
        if (str_starts_with($tag, "trackmember-")) {
            $track_tag = substr($tag, 12);
            $current_tracks[$track_tag] = true;
        }
    }
}

// Track display names
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
];

$Qreq->print_header("Track Membership", "account", ["action_bar" => ""]);

echo '<div class="container-fluid">';
echo '<div class="row">';
echo '<div class="col-lg-8 offset-lg-2">';

echo '<h2>Serving Tracks</h2>';
echo '<p class="mb-4">Select the tracks you want to participate in. The system will automatically add the corresponding trackmember-tag for you.</p>';

echo Ht::form($Conf->hoturl("track_membership"), ["class" => "need-unload-protection"]);

$all_tracks = $Conf->all_tracks();
$has_tracks = false;

foreach ($all_tracks as $track) {
    if ($track->is_default) continue;
    $has_tracks = true;
    
    $track_tag = $track->tag;
    $display_name = $track_display_names[$track_tag] ?? $track_tag;
    $is_checked = isset($current_tracks[$track_tag]);
    
    echo '<div class="form-check mb-3">';
    echo Ht::checkbox("track_{$track_tag}", 1, $is_checked, [
        "class" => "form-check-input",
        "id" => "track_{$track_tag}"
    ]);
    echo '<label class="form-check-label ms-2" for="track_' . htmlspecialchars($track_tag) . '">';
    echo '<strong>' . htmlspecialchars($display_name) . '</strong>';
    echo ' <span class="text-muted">(' . htmlspecialchars($track_tag) . ')</span>';
    echo '</label>';
    echo '</div>';
}

if (!$has_tracks) {
    echo '<p class="alert alert-info">No non-default tracks are currently configured in the system.</p>';
} else {
    echo '<div class="form-group mt-4">';
    echo Ht::submit("save_track_membership", "Save Settings", ["class" => "btn btn-primary me-2"]);
    echo Ht::link("Back to Profile", $Conf->hoturl("profile"), ["class" => "btn btn-secondary"]);
    echo '</div>';
}

echo '</form>';

// Add some JavaScript for better user experience
echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    var form = document.querySelector("form");
    var checkboxes = form.querySelectorAll("input[type=checkbox]");
    var submitBtn = form.querySelector("input[type=submit]");
    
    function updateSubmitButton() {
        var hasChanges = false;
        checkboxes.forEach(function(cb) {
            if (cb.defaultChecked !== cb.checked) {
                hasChanges = true;
            }
        });
        
        if (hasChanges) {
            submitBtn.classList.add("btn-warning");
            submitBtn.classList.remove("btn-primary");
            submitBtn.value = "Save Changes";
        } else {
            submitBtn.classList.add("btn-primary");
            submitBtn.classList.remove("btn-warning");
            submitBtn.value = "Save Settings";
        }
    }
    
    checkboxes.forEach(function(cb) {
        cb.addEventListener("change", updateSubmitButton);
    });
});
</script>';

echo '</div>'; // col
echo '</div>'; // row
echo '</div>'; // container

$Qreq->print_footer(); 