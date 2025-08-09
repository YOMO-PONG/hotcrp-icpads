<?php
// pages/p_ieeeecf.php -- HotCRP IEEE Electronic Copyright Form integration
// Copyright (c) 2025 HotCRP contributors; see LICENSE.

class IeeeEcf_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var Qrequest */
    public $qreq;
    /** @var ?PaperInfo */
    public $prow;

    function __construct(Contact $user, Qrequest $qreq) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
    }



    /** @return never */
    private function error_exit(string $message) {
        echo "<div class='msg msg-error'>";
        echo "<h5>Error</h5>";
        echo "<p>" . htmlspecialchars($message) . "</p>";
        echo "<p><a href='" . htmlspecialchars($this->conf->hoturl("index")) . "'>Return to home page</a></p>";
        echo "</div>";
        throw new PageCompletion;
    }

    function print_form() {
        $paperId = $this->qreq->paperId ?? $this->qreq->p ?? 0;
        if (!$paperId) {
            $this->error_exit("Paper ID is required.");
        }

        echo '<div style="max-width: 800px; margin: 2em auto; padding: 2em; border: 1px solid #ccc; border-radius: 8px; background: #fff; box-shadow: 0 2px 10px rgba(0,0,0,0.1); font-family: sans-serif;">';
        echo '<h1 style="color: #005696; border-bottom: 2px solid #eee; padding-bottom: 0.5em; margin-top: 0;">IEEE Electronic Copyright Form (eCF)</h1>';
        echo '<p style="color: #555;">Please fill out the following information for your paper submission to IEEE. All fields are required.</p>';
        
        echo '<form action="https://ecopyright.ieee.org/ECTT/IntroPage.jsp" method="post">';
        
        echo '<table style="width: 100%; border-collapse: collapse; margin: 20px 0;">';
        
        // Publication Title
        echo '<tr>';
        echo '<td style="padding: 10px; border: 1px solid #ddd; background-color: #f8f9fa; font-weight: bold; width: 200px;">Publication Title *</td>';
        echo '<td style="padding: 10px; border: 1px solid #ddd;">';
        echo '<input type="text" name="PubTitle" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" ';
        echo 'value="2025 IEEE 31th International Conference on Parallel and Distributed Systems (ICPADS)" required>';
        echo '</td>';
        echo '</tr>';
        
        // Article Title
        echo '<tr>';
        echo '<td style="padding: 10px; border: 1px solid #ddd; background-color: #f8f9fa; font-weight: bold;">Article Title *</td>';
        echo '<td style="padding: 10px; border: 1px solid #ddd;">';
        echo '<input type="text" name="ArtTitle" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" ';
        echo 'placeholder="Enter your paper title" required>';
        echo '</td>';
        echo '</tr>';
        
        // Author Names
        echo '<tr>';
        echo '<td style="padding: 10px; border: 1px solid #ddd; background-color: #f8f9fa; font-weight: bold;">Author Names *</td>';
        echo '<td style="padding: 10px; border: 1px solid #ddd;">';
        echo '<input type="text" name="AuthName" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" ';
        echo 'placeholder="Enter all author names (e.g., John Smith, Jane Doe)" required>';
        echo '<small style="color: #666; display: block; margin-top: 5px;">Enter all authors separated by commas</small>';
        echo '</td>';
        echo '</tr>';
        
        // Author Email
        echo '<tr>';
        echo '<td style="padding: 10px; border: 1px solid #ddd; background-color: #f8f9fa; font-weight: bold;">Author Email *</td>';
        echo '<td style="padding: 10px; border: 1px solid #ddd;">';
        echo '<input type="email" name="AuthEmail" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;" ';
        echo 'placeholder="Enter contact email address" required>';
        echo '<small style="color: #666; display: block; margin-top: 5px;">Primary contact email for correspondence</small>';
        echo '</td>';
        echo '</tr>';
        
        // Article ID (auto-filled)
        echo '<tr>';
        echo '<td style="padding: 10px; border: 1px solid #ddd; background-color: #f8f9fa; font-weight: bold;">Article ID</td>';
        echo '<td style="padding: 10px; border: 1px solid #ddd;">';
        echo '<input type="text" name="ArtId" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; background-color: #f5f5f5;" ';
        echo 'value="' . htmlspecialchars($paperId) . '" readonly>';
        echo '<small style="color: #666; display: block; margin-top: 5px;">Paper ID from HotCRP system</small>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        // Hidden required fields
        echo '<input type="hidden" name="ArtSource" value="67057">';
        echo '<input type="hidden" name="rtrnurl" value="' . htmlspecialchars($this->conf->hoturl("paper", ["p" => $paperId])) . '">';
        
        echo '<div style="background-color: #e8f4fd; border: 1px solid #bee5eb; padding: 15px; border-radius: 4px; margin: 20px 0;">';
        echo '<h4 style="margin-top: 0; color: #0c5460;">Instructions:</h4>';
        echo '<ul style="margin-bottom: 0; color: #0c5460;">';
        echo '<li>Please ensure all information is accurate before submitting</li>';
        echo '<li>The Article Title should match exactly what appears in your PDF</li>';
        echo '<li>Author names should be in the same order as they appear in your paper</li>';
        echo '<li>Use the email address you want IEEE to use for correspondence</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '<p style="margin: 20px 0; text-align: center;">When you are ready, click the button below to proceed to the IEEE website to complete the copyright transfer.</p>';

        echo '<button type="submit" style="background-color: #00629b; color: white; padding: 15px 30px; border: none; border-radius: 4px; cursor: pointer; font-size: 1.1em; font-weight: bold; display: block; width: 100%; text-align: center; margin: 20px 0;">';
        echo 'Submit to IEEE Copyright Form →';
        echo '</button>';

        echo '</form>';
        echo '</div>';
    }

    static function go(Contact $user, Qrequest $qreq, ComponentSet $pc, $gj) {
        $pp = new IeeeEcf_Page($user, $qreq);
        $pp->print_form();
    }
} 