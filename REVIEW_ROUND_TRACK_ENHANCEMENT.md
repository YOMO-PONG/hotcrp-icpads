# HotCRP 审稿轮次选择和Track筛选增强功能

## 功能概述

本增强功能为HotCRP系统的PC审稿人分配页面添加了以下两个核心功能：
1. **审稿轮次选择**：支持选择R1（第一轮）或R2（第二轮）审稿
2. **Track成员筛选**：只显示属于当前论文track的审稿人

## 核心特性

### 1. 审稿轮次选择
- **界面位置**: 推荐审稿人区域顶部的下拉选择框
- **可选轮次**: R1（第一轮）、R2（第二轮）
- **默认值**: R1
- **功能**: 分配审稿人时自动应用选择的轮次

### 2. Track成员筛选
- **自动检测**: 系统自动识别论文所属的track
- **筛选规则**: 只显示具有 `trackmember-{track标签}` tag的PC成员
- **界面提示**: 显示当前论文的track信息和筛选说明
- **智能回退**: 如果论文没有track标签，则显示所有符合条件的PC成员

### 3. 中文界面优化
- **完整中文化**: 所有界面文本均已翻译为中文
- **友好提示**: 清晰的说明文本帮助用户理解功能

## 技术实现

### 核心文件修改
- **主要文件**: `src/pages/p_assign.php`
- **修改类**: `Assign_Page`

### 新增方法

#### `get_paper_track_tag(PaperInfo $prow)`
- **功能**: 获取论文所属的track标签
- **返回**: 论文的track标签字符串，如果没有则返回null
- **逻辑**: 遍历所有配置的track标签，检查论文是否具有相应标签

#### `is_track_member(Contact $pc, $track_tag)`
- **功能**: 检查PC成员是否属于指定track
- **参数**: PC成员对象和track标签
- **返回**: 布尔值表示是否为track成员
- **检查规则**: 验证PC成员是否具有 `trackmember-{track_tag}` 标签

### 修改方法

#### `get_recommended_reviewers()` 增强
```php
// 新增track筛选逻辑
$paper_track = $this->get_paper_track_tag($prow);

foreach ($this->conf->pc_members() as $pc) {
    // 原有筛选逻辑...
    
    // 新增: Track成员筛选
    if ($paper_track && !$this->is_track_member($pc, $paper_track)) {
        continue;
    }
    
    // 继续处理...
}
```

#### `handle_pc_update()` 轮次处理
```php
// 获取选择的审稿轮次
$selected_round = $this->qreq->selected_round ?? "R1";
if (($rname = $this->conf->sanitize_round_name($selected_round)) === "") {
    $rname = "R1"; // 默认为R1
}
```

#### `print_recommended_reviewers()` 界面增强
- 添加审稿轮次选择下拉框
- 添加track信息显示
- 中文化所有界面文本

### JavaScript功能增强

#### 轮次选择处理
```javascript
// 轮次选择器事件监听
var roundSelect = document.getElementById("review-round-select");
if (roundSelect) {
    roundSelect.addEventListener("change", function() {
        currentRound = this.value;
        updateAssignmentForm();
    });
}

// 更新表单隐藏字段
function updateAssignmentForm() {
    var form = document.getElementById("f-pc-assignments");
    if (form) {
        var hiddenInput = form.querySelector("input[name='selected_round']");
        if (!hiddenInput) {
            hiddenInput = document.createElement("input");
            hiddenInput.type = "hidden";
            hiddenInput.name = "selected_round";
            form.appendChild(hiddenInput);
        }
        hiddenInput.value = currentRound;
    }
}
```

#### Track信息显示
```javascript
// 显示track信息
function showTrackInfo() {
    var paperTrack = window.paperTrack || null;
    if (paperTrack) {
        var trackInfo = document.createElement("div");
        trackInfo.className = "track-info";
        trackInfo.innerHTML = "<strong>当前论文Track：</strong>" + paperTrack + 
                            " <em>（仅显示该Track的成员：trackmember-" + paperTrack + "）</em>";
        
        var recommendedView = document.getElementById("recommended-view");
        if (recommendedView) {
            recommendedView.insertBefore(trackInfo, recommendedView.firstChild);
        }
    }
}
```

### CSS样式增强
```css
/* 轮次选择器样式 */
.review-round-selector { 
    display: flex; 
    align-items: center; 
    gap: 0.5rem;
}

.review-round-selector select {
    padding: 0.25rem 0.5rem;
    border: 1px solid #ced4da;
    border-radius: 0.25rem;
    background: white;
    font-size: 0.9em;
}

/* Track信息提示样式 */
.track-info {
    background: #e9ecef;
    padding: 0.5rem;
    border-radius: 0.25rem;
    margin-bottom: 1rem;
    font-size: 0.9em;
}
```

## 使用指南

### 轨道主席操作流程

1. **进入审稿人分配页面**
   - 访问论文详情页面
   - 点击"PC审稿人分配"区域

2. **查看Track信息**
   - 系统自动显示论文所属的track
   - 显示当前筛选规则说明

3. **选择审稿轮次**
   - 在页面顶部选择R1或R2轮次
   - 默认选择R1（第一轮）

4. **查看推荐审稿人**
   - 系统自动筛选该track的成员
   - 按Topic Score排序显示前10位
   - 绿色左边框标识推荐成员

5. **调整筛选设置**
   - 可选择是否排除有冲突的审稿人
   - 实时更新推荐列表

6. **执行分配操作**
   - 使用标准的HotCRP分配界面
   - 选择的轮次会自动应用
   - 点击"保存分配"完成操作

### 界面元素说明

- **轮次选择框**: 位于推荐审稿人标题右侧
- **Track信息条**: 显示当前论文track和筛选说明
- **推荐审稿人**: 绿色左边框，按分数排序
- **冲突审稿人**: 红色背景，半透明显示
- **T#**: Topic Interest Score（主题兴趣分数）
- **P#**: Review Preference（审稿偏好）

## 配置要求

### 系统设置
1. **Track配置**: 确保系统已正确配置track标签
2. **用户标签**: PC成员需要正确设置track成员标签
   - 格式: `trackmember-{track_name}`
   - 例如: `trackmember-track-a`, `trackmember-track-b`
3. **论文标签**: 论文需要标记所属track
   - 使用系统配置的track标签

### 标签命名约定
- **Track成员**: `trackmember-{track-name}`
- **示例**: 
  - Track A的成员: `trackmember-track-a`
  - Track B的成员: `trackmember-track-b`

## 兼容性说明

### 向后兼容
- 保留所有原有功能和API
- 无track配置的系统正常工作
- 无track标签的论文显示所有合格审稿人
- 现有的分配逻辑完全不变

### 浏览器支持
- 现代浏览器(Chrome 60+, Firefox 55+, Safari 12+)
- 支持ES5+的JavaScript环境
- CSS3选择器和Flexbox支持

## 性能优化

### 数据处理优化
- 单次查询获取所有必要信息
- 高效的标签匹配算法
- 客户端排序减少服务器负荷

### 渲染优化
- 按需加载track信息
- 最小化DOM操作
- CSS样式内联减少网络请求

## 故障排除

### 常见问题
1. **没有显示推荐审稿人**: 
   - 检查论文是否有track标签
   - 验证PC成员的track成员标签
2. **轮次选择不生效**: 
   - 确认JavaScript正常加载
   - 检查表单提交逻辑
3. **Track信息不显示**: 
   - 验证论文的track标签配置
   - 检查系统track设置

### 调试方法
1. 检查浏览器控制台错误信息
2. 验证PHP错误日志中的相关信息
3. 确认数据库中的标签数据完整性

## 扩展建议

### 功能增强
1. **多轮次批量分配**: 支持一次性为多个轮次分配审稿人
2. **Track间协调**: 跨track的审稿人推荐和协调
3. **轮次负载均衡**: 自动平衡不同轮次的审稿负担
4. **历史记录**: 记录轮次分配的历史和统计

### 数据展示
1. **轮次统计**: 显示各轮次的分配统计
2. **Track分析**: 各track的审稿人分布和能力分析
3. **负载可视化**: 图表显示审稿人的轮次负荷

## 维护说明

### 定期检查
- Track标签配置的一致性
- PC成员track标签的准确性
- 轮次分配的合理性

### 更新建议
- 根据会议组织结构调整track配置
- 定期更新PC成员的track归属
- 收集用户反馈持续改进界面体验

## 版本信息

- **版本**: 1.0
- **发布日期**: 2024年
- **兼容版本**: HotCRP当前版本
- **作者**: 系统开发团队

---

## 总结

本次增强为HotCRP的审稿人分配功能带来了更精确的轮次管理和track-based的智能筛选，显著提升了大型会议中审稿人分配的效率和准确性。通过中文化界面和友好的用户体验设计，轨道主席可以更便捷地进行审稿人管理工作。 