<?php
// o_topics.php -- HotCRP helper class for topics intrinsic
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Topics_PaperOption extends CheckboxesBase_PaperOption {
    function __construct(Conf $conf, $args) {
        parent::__construct($conf, $args);
        $this->refresh_topic_set();
    }

    function refresh_topic_set() {
        $ts = $this->topic_set();
        $empty = $ts->count() === 0 && !$ts->auto_add();
        $this->override_exists_condition($empty ? false : null);
    }

    function topic_set() {
        return $this->conf->topic_set();
    }

    function interests($user) {
        return $user ? $user->topic_interest_map() : [];
    }

    function value_force(PaperValue $ov) {
        if ($this->id === PaperOption::TOPICSID) {
            $vs = $ov->prow->topic_list();
            $ov->set_value_data($vs, array_fill(0, count($vs), null));
        }
    }

    private function _store_new_values(PaperValue $ov, PaperStatus $ps) {
        $this->topic_set()->commit_auto_add();
        $vs = $ov->value_list();
        $newvs = $ov->anno("new_values");
        '@phan-var list<string> $newvs';
        foreach ($newvs as $tk) {
            if (($tid = $this->topic_set()->find_exact($tk)) !== null) {
                $vs[] = $tid;
            }
        }
        $this->topic_set()->sort($vs); // to reduce unnecessary diffs
        $ov->set_value_data($vs, array_fill(0, count($vs), null));
        $ov->set_anno("new_values", null);
    }

    function value_save(PaperValue $ov, PaperStatus $ps) {
        if (!$ov->anno("new_values")
            && $ov->equals($ov->prow->base_option($this->id))) {
            return true;
        }
        if ($ov->anno("new_values")) {
            if (!$ps->save_status_prepared()) {
                $ps->request_resave($this);
                $ps->change_at($this);
            } else {
                $this->_store_new_values($ov, $ps);
            }
        }
        $ps->change_at($this);
        if ($this->id === PaperOption::TOPICSID) {
            $ov->prow->set_prop("topicIds", join(",", $ov->value_list()));
        }
        return true;
    }

    function print_web_edit(PaperTable $pt, $ov, $reqov) {
        $topicset = $this->topic_set();
        
        // 如果有Track层次结构，显示特殊界面
        if ($topicset->has_tracks()) {
            $this->print_hierarchical_web_edit($pt, $ov, $reqov);
        } else {
            // 使用父类的标准显示
            parent::print_web_edit($pt, $ov, $reqov);
        }
    }

    private function print_hierarchical_web_edit(PaperTable $pt, $ov, $reqov) {
        $topicset = $this->topic_set();
        $tracks = $topicset->get_tracks();
        $track_topic_map = $topicset->get_track_topic_map();
        
        // ===== 新增：轨道系统标签到显示名称的映射关系 =====
        $track_display_names = [
            // 轨道系统标签 => 完整显示名称
            'cloud-edge' => 'Cloud & Edge Computing ',
            'wsmc' => 'Wireless Sensing & Mobile Computing ',
            'ii-internet' => 'Industrial Informatics & Industrial Internet ',
            'infosec' => 'Information Security',
            'sads' => 'System and Applied Data Science',
            'big-data-fm' => 'Big Data & Foundation Models ',
            'aigc-mapc' => 'AIGC & Multi-Agent Parallel Computing',
            'dist-storage' => 'Distributed Storage',
            // 您可以根据实际需要继续添加更多轨道映射...
        ];
        // =====================================================
        
        $pt->print_editable_option_papt($this, null, [
            "id" => $this->readable_formid(),
            "for" => false,
        ]);
        
        // 生成Track-Topic映射的JavaScript对象
        $js_map = [];
        foreach ($track_topic_map as $track => $topic_ids) {
            $topics = [];
            foreach ($topic_ids as $tid) {
                $topics[] = [
                    'id' => $tid,
                    'name' => htmlspecialchars($topicset->name($tid)),
                    'checked' => in_array($tid, $reqov->value_list()),
                    'default_checked' => in_array($tid, $ov->value_list())
                ];
            }
            $js_map[$track] = $topics;
        }
        
        echo '<script>
window.hotcrpTrackTopicMap = ', json_encode($js_map), ';
</script>';
        
        echo '<fieldset class="papev fieldset-covert" name="', $this->formid, '">
        <div class="f-i">
            <label for="track_selector"><strong>Main Track</strong></label>
            <select id="track_selector" class="uich" name="track_selector">
                <option value="">Select a track...</option>';
        
        // ===== 修改：使用映射关系生成轨道选项 =====
        foreach ($tracks as $track_tag) {
            // 获取显示名称，如果没有映射则使用原标签
            $display_name = $track_display_names[$track_tag] ?? $track_tag;
            // value属性仍然是系统标签，但显示文本是完整名称
            echo '<option value="', htmlspecialchars($track_tag), '">', htmlspecialchars($display_name), '</option>';
        }
        // =============================================
        
        echo '</select>
        </div>
        <div class="f-i" id="topics_container" style="display: none;">
            <label><strong>Topics</strong></label>
            <ul class="ctable" id="topics_list">
            </ul>
        </div>
        </fieldset>';
        
        // 添加JavaScript逻辑
        echo '<script>
(function() {
    var trackSelector = document.getElementById("track_selector");
    var topicsContainer = document.getElementById("topics_container");
    var topicsList = document.getElementById("topics_list");
    var formId = "', $this->formid, '";
    
    // 预先设置已选择的轨道（如果有的话）
    var preSelectedTrack = null;
    var selectedTopics = [];
    ';
    
    // 检查是否有预选的主题，确定应该显示哪个track
    if (!empty($reqov->value_list())) {
        echo 'var preSelectedTopicIds = [', implode(',', $reqov->value_list()), '];
        for (var track in window.hotcrpTrackTopicMap) {
            var topics = window.hotcrpTrackTopicMap[track];
            for (var i = 0; i < topics.length; i++) {
                if (preSelectedTopicIds.indexOf(topics[i].id) !== -1) {
                    preSelectedTrack = track;
                    break;
                }
            }
            if (preSelectedTrack) break;
        }
        
        if (preSelectedTrack) {
            trackSelector.value = preSelectedTrack;
            updateTopicsList(preSelectedTrack);
            topicsContainer.style.display = "block";
        }';
    }
    
    echo '
    trackSelector.addEventListener("change", function() {
        var selectedTrack = this.value;
        if (selectedTrack === "") {
            topicsContainer.style.display = "none";
            topicsList.innerHTML = "";
        } else {
            updateTopicsList(selectedTrack);
            topicsContainer.style.display = "block";
        }
    });
    
    function updateTopicsList(track) {
        var topics = window.hotcrpTrackTopicMap[track] || [];
        topicsList.innerHTML = "";
        
        topics.forEach(function(topic) {
            var li = document.createElement("li");
            li.className = "ctelt";
            
            var label = document.createElement("label");
            label.className = "checki ctelti";
            
            var span = document.createElement("span");
            span.className = "checkc";
            
            var checkbox = document.createElement("input");
            checkbox.type = "checkbox";
            checkbox.name = formId + ":" + topic.id;
            checkbox.value = "1";
            checkbox.className = "uic js-range-click topic-entry";
            checkbox.setAttribute("data-range-type", formId);
            checkbox.setAttribute("data-default-checked", topic.default_checked);
            if (topic.checked) {
                checkbox.checked = true;
            }
            
            span.appendChild(checkbox);
            label.appendChild(span);
            label.appendChild(document.createTextNode(topic.name));
            li.appendChild(label);
            topicsList.appendChild(li);
        });
    }
})();
</script>';
        
        echo "</div>\n\n";
    }
}
