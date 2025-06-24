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
        
        $pt->print_editable_option_papt($this, null, [
            "id" => $this->readable_formid(),
            "for" => false,
        ]);
        
        // 生成Track-Topic映射的JavaScript对象
        $js_map = [];
        foreach ($track_topic_map as $track => $topic_ids) {
            $topics = [];
            foreach ($topic_ids as $tid) {
                $topic_name = $topicset->name($tid);
                $topics[] = [
                    'id' => $tid,
                    'name' => htmlspecialchars($topic_name),
                    'checked' => in_array($tid, $reqov->value_list()),
                    'default_checked' => in_array($tid, $ov->value_list())
                ];
            }
            $js_map[$track] = $topics;
        }
        
        // 将Track-Topic映射数据注入到JavaScript全局变量
        echo '<script>
window.hotcrpTrackTopicMap = ', json_encode($js_map), ';
</script>';
        
        // 只显示Topics容器，不再显示冗余的Main Track选择器
        echo '<fieldset class="papev fieldset-covert" name="', $this->formid, '">
        <div class="f-i" id="topics_container">
            <ul class="ctable" id="topics_list">
            </ul>
        </div>
        </fieldset>';
        
        // 添加JavaScript逻辑来监听第一个Track字段的变化
        echo '<script>
(function() {
    var topicsContainer = document.getElementById("topics_container");
    var topicsList = document.getElementById("topics_list");
    var formId = "', $this->formid, '";
    
    // 查找主Track选择器
    var mainTrackSelector = null;
    var allSelects = document.querySelectorAll("select");
    
    for (var i = 0; i < allSelects.length; i++) {
        var select = allSelects[i];
        var selectName = (select.name || "").toLowerCase();
        var selectId = (select.id || "").toLowerCase();
        
        // 检查name或id是否包含track
        if (selectName.includes("track") || selectId.includes("track")) {
            if (select.id !== "track_selector") {
                mainTrackSelector = select;
                break;
            }
        }
        
        // 检查选项内容是否看起来像轨道选择器
        var hasTrackOptions = false;
        for (var j = 0; j < select.options.length; j++) {
            var optText = select.options[j].text.toLowerCase();
            var optValue = select.options[j].value.toLowerCase();
            if (optText.includes("cloud") || optText.includes("computing") || 
                optText.includes("security") || optText.includes("data") ||
                optValue.includes("cloud") || optValue.includes("edge")) {
                hasTrackOptions = true;
                break;
            }
        }
        
        if (hasTrackOptions && select.id !== "track_selector") {
            mainTrackSelector = select;
            break;
        }
    }
    
    if (!mainTrackSelector) {
        return;
    }
    
    function updateTopicsList(trackValue) {
        if (!trackValue || trackValue === "") {
            topicsList.innerHTML = "";
            topicsContainer.style.display = "none";
            return;
        }
        
        // 轨道值映射
        var trackMapping = {
            "Cloud & Edge Computing": "cloud-edge",
            "Wireless Sensing & Mobile Computing": "wsmc", 
            "Industrial Informatics & Internet": "ii-internet",
            "Information Security": "infosec",
            "System and Applied Data Science": "sads",
            "Big Data & Foundation Models": "big-data-fm",
            "AIGC & Multi-Agent Parallel Computing": "aigc-mapc",
            "Distributed Storage": "dist-storage",
            "Next-Generation Mobile Networks and Connected Systems": "ngm",
            "RF Computing and AIoT Application": "rfa",
            "Distributed System and Ubiquitous Intelligence": "dsui",
            "Wireless and Mobile AIoT": "wma",
            "Big Data and Machine Learning Systems": "bdmls",
            "SS:Networked Computing for Embodied AI": "ncea",
            "Artificial Intelligence for Mobile Computing": "aimc",
            "Intelligent Data Processing & Management": "idpm",
            "Blockchain & Activation of Data Value": "badv",
            "SS:Millimeter-Wave and Terahertz Sensing and Networks": "mwt",
            "Interdisciplinary Distributed System and IoT Applications": "idsia"
        };
        
        var internalTrack = trackMapping[trackValue] || trackValue.toLowerCase() || trackValue;
        var topics = window.hotcrpTrackTopicMap[internalTrack] || [];
        
        // 如果没找到，尝试其他可能的键
        if (topics.length === 0) {
            var availableKeys = Object.keys(window.hotcrpTrackTopicMap || {});
            for (var k = 0; k < availableKeys.length; k++) {
                var key = availableKeys[k];
                if (key.toLowerCase().includes(trackValue.toLowerCase()) || 
                    trackValue.toLowerCase().includes(key.toLowerCase())) {
                    topics = window.hotcrpTrackTopicMap[key];
                    break;
                }
            }
        }
        
        // 精确匹配
        if (topics.length === 0 && window.hotcrpTrackTopicMap[trackValue]) {
            topics = window.hotcrpTrackTopicMap[trackValue];
        }
        
        // 清空现有topics
        topicsList.innerHTML = "";
        
        if (topics.length === 0) {
            topicsContainer.style.display = "none";
            return;
        }
        
        // 生成topic复选框
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
        
        topicsContainer.style.display = "block";
    }
    
    // 监听主Track选择器的变化
    function bindTrackSelector() {
        // 初始化
        var currentValue = mainTrackSelector.value;
        var currentText = mainTrackSelector.selectedIndex >= 0 ? mainTrackSelector.options[mainTrackSelector.selectedIndex].text : "";
        
        if (currentValue && currentValue !== "") {
            updateTopicsList(currentValue);
            // 也尝试使用显示文本
            if (currentText && currentText !== currentValue) {
                setTimeout(function() {
                    if (topicsList.children.length === 0) {
                        updateTopicsList(currentText);
                    }
                }, 100);
            }
        }
        
        // 监听变化事件
        mainTrackSelector.addEventListener("change", function() {
            var selectedTrack = this.value;
            var selectedText = this.selectedIndex >= 0 ? this.options[this.selectedIndex].text : "";
            
            updateTopicsList(selectedTrack);
            // 如果按值没找到，尝试按显示文本
            setTimeout(function() {
                if (topicsList.children.length === 0) {
                    updateTopicsList(selectedText);
                }
            }, 100);
        });
        
        // 监听点击事件
        mainTrackSelector.addEventListener("click", function() {
            var selectedTrack = this.value;
            var selectedText = this.selectedIndex >= 0 ? this.options[this.selectedIndex].text : "";
            
            if (selectedTrack && selectedTrack !== "") {
                setTimeout(function() {
                    updateTopicsList(selectedTrack);
                    if (topicsList.children.length === 0) {
                        updateTopicsList(selectedText);
                    }
                }, 50);
            }
        });
    }
    
    // 立即绑定或等待DOM准备
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", bindTrackSelector);
    } else {
        bindTrackSelector();
    }
    
})();
</script>';
        
        echo "</div>\n\n";
    }
}
