# HotCRP PC成员选择器增强功能

## 功能概述

本增强功能对HotCRP的"Manual Assignment by Reviewer"页面中的PC成员选择器进行了全面重构，实现了智能分组、工作量显示和搜索功能，大幅提升了主席和轨道主席在大量审稿人中进行选择的效率。

## 核心特性

### 1. 智能排除不相关人员
- **自动排除PC Chair**: 系统自动过滤掉所有`privChair`为true的用户
- **只显示有效审稿人**: 确保选择器中只包含真正参与审稿工作的PC成员

### 2. 工作量信息直接显示
- **格式**: `姓名 [Total Reviews: N (M primary)]`
- **实时数据**: 基于`AssignmentCountSet`获取最新的审稿分配统计
- **详细信息**: 显示总审稿数和主要审稿数，帮助主席平衡工作负荷

### 3. 智能分组显示
使用HTML `<optgroup>` 标签实现逻辑分组：

#### Track Chairs (轨道主席)
- 识别标准: 用户标签包含 `{track-name}-chair#` 格式
- 显示位置: 选择器顶部，蓝色背景
- 排序方式: 按姓名字母顺序

#### Track Members (轨道成员)
- 识别标准: 用户标签包含 `{track-name}-member#` 格式
- 分组显示: 每个轨道单独成组，如"Track A Members"
- 显示位置: 轨道主席之后，紫色背景
- 排序方式: 组内按姓名字母顺序

#### Other PC Members (其他PC成员)
- 包含范围: 所有不属于特定轨道的普通PC成员
- 显示位置: 选择器底部，绿色背景
- 排序方式: 按姓名字母顺序

### 4. 视觉优化和用户体验
- **现代化字体**: 使用系统原生字体栈
- **颜色编码**: 不同分组使用不同背景色便于识别
- **响应式设计**: 最小宽度400px，适配各种屏幕
- **悬停效果**: 选项悬停时的视觉反馈

### 5. 搜索增强功能
- **实时搜索**: 动态输入框支持即时过滤
- **智能匹配**: 按姓名、邮箱等信息搜索
- **无缝集成**: 搜索框自动插入选择器旁边

## 技术实现

### 核心方法

#### `get_grouped_pc_options($acs)`
- **功能**: 生成分组的PC成员选项数组
- **参数**: `AssignmentCountSet` 对象用于获取工作量统计
- **返回**: 包含分组信息的多维数组
- **核心逻辑**:
  1. 遍历所有PC成员，排除`privChair`
  2. 获取每个成员的审稿工作量统计
  3. 解析`contactTags`确定轨道归属
  4. 按分组规则组织数据
  5. 对各组内成员进行排序

#### `render_grouped_pc_selector($grouped_options, $selected_email)`
- **功能**: 渲染分组的HTML select元素
- **参数**: 分组选项数组和当前选中邮箱
- **返回**: 完整的HTML select字符串
- **核心逻辑**:
  1. 生成默认选项
  2. 按顺序渲染各个optgroup
  3. 处理选中状态
  4. 应用安全的HTML转义

### 标签识别机制

系统通过分析用户的`contactTags`字段来确定轨道归属：

```php
// 轨道主席识别
$chair_tag = $track_tag . '-chair';
if (stripos($pc->contactTags, " {$chair_tag}#") !== false) {
    // 用户是该轨道的主席
}

// 轨道成员识别  
$member_tag = $track_tag . '-member';
if (stripos($pc->contactTags, " {$member_tag}#") !== false) {
    // 用户是该轨道的成员
}
```

### 工作量计算

利用HotCRP现有的`AssignmentCountSet`类：

```php
$ac = $acs->get($pc->contactId);
$workload_info = " [Total Reviews: {$ac->rev}";
if ($ac->pri > 0) {
    $workload_info .= " ({$ac->pri} primary)";
}
$workload_info .= "]";
```

## 使用效果示例

### 选择器界面展示
```
▼ Track Chairs
   Jane Smith [Total Reviews: 5 (2 primary)]
   John Doe [Total Reviews: 3 (1 primary)]
   
▼ Track A Members  
   Alice Johnson [Total Reviews: 4 (2 primary)]
   Bob Wilson [Total Reviews: 2 (1 primary)]
   
▼ Track B Members
   Carol Davis [Total Reviews: 6 (3 primary)]
   David Brown [Total Reviews: 1 (0 primary)]
   
▼ Other PC Members
   Eva Garcia [Total Reviews: 3 (1 primary)]
   Frank Miller [Total Reviews: 8 (4 primary)]
```

### 搜索功能演示
- 输入 "smith" → 只显示包含"smith"的选项
- 输入 "track a" → 显示Track A相关的所有成员
- 输入 "5" → 显示工作量包含数字5的审稿人

## 配置要求

### 系统设置
1. **轨道配置**: 确保系统已配置相应的轨道标签
2. **用户标签**: PC成员需要正确设置轨道相关标签
3. **权限检查**: 确保调用用户有足够权限查看PC成员信息

### 标签命名约定
- 轨道主席: `{track-name}-chair`
- 轨道成员: `{track-name}-member`
- 示例: `track-a-chair`, `track-b-member`

## 兼容性说明

### 向后兼容
- 保留所有原有功能和API
- 无标签用户自动归入"Other PC Members"组
- 现有的表单提交逻辑完全不变

### 浏览器支持
- 现代浏览器(Chrome 60+, Firefox 55+, Safari 12+)
- 支持ES5+的JavaScript环境
- CSS3选择器和Flexbox支持

## 性能优化

### 数据处理优化
- 单次数据库查询获取所有PC成员信息
- 复用现有的`AssignmentCountSet`缓存机制
- 客户端排序减少服务器负荷

### 渲染优化
- HTML字符串拼接而非DOM操作
- CSS样式内联减少网络请求
- JavaScript功能按需加载

## 扩展建议

### 功能增强
1. **批量分配**: 支持一次选择多个审稿人
2. **工作量平衡**: 显示工作量分布图表
3. **个性化排序**: 支持用户自定义排序规则
4. **历史记录**: 记住用户的选择偏好

### 数据展示
1. **详细统计**: 鼠标悬停显示更多审稿人信息
2. **可用性指示**: 显示审稿人的空闲状态
3. **专业匹配**: 基于论文主题推荐最适合的审稿人

## 故障排除

### 常见问题
1. **选择器显示空白**: 检查用户权限和PC成员配置
2. **分组不正确**: 验证用户标签格式和轨道配置
3. **工作量显示错误**: 确认`AssignmentCountSet`数据同步

### 调试方法
1. 检查浏览器控制台错误信息
2. 验证PHP错误日志中的相关信息
3. 使用开发者工具检查HTML结构和CSS样式

## 维护说明

### 定期检查
- 用户标签的一致性和准确性
- 轨道配置的更新和同步
- 工作量统计的数据完整性

### 更新建议
- 根据会议规模调整分组策略
- 定期优化搜索算法性能
- 收集用户反馈持续改进界面体验 

 实现的核心功能
1. 智能排除PC Chair
在get_grouped_pc_options()方法中添加了if ($pc->privChair) continue;
确保PC主席不会出现在审稿人选择列表中
2. 工作量信息直接显示
格式：姓名 [Total Reviews: N (M primary)]
利用现有的AssignmentCountSet类获取实时审稿统计
显示总审稿数和主要审稿数，帮助平衡工作负荷
3. 智能分组显示
使用HTML <optgroup> 实现三级分组：
Track Chairs: 轨道主席（蓝色背景）
Track Members: 按轨道分组的成员（紫色背景）
Other PC Members: 其他PC成员（绿色背景）
4. 标签识别机制
轨道主席：识别{track-name}-chair#格式标签
轨道成员：识别{track-name}-member#格式标签
通过contactTags字段解析用户归属
5. 视觉优化和搜索功能
现代化CSS样式，颜色编码区分不同分组
实时搜索框，支持按姓名、邮箱过滤
响应式设计，最小宽度400px
📁 修改的文件
src/pages/p_manualassign.php - 主要实现文件
添加了get_grouped_pc_options()方法
添加了render_grouped_pc_selector()方法
修改了PC成员选择器的渲染逻辑
添加了CSS样式和JavaScript增强功能
PC_SELECTOR_ENHANCEMENT.md - 完整的技术文档
详细的功能说明和使用指南
技术实现细节和配置要求
故障排除和维护建议
🔧 技术特点
向后兼容：保留所有原有功能和API
性能优化：单次查询，客户端排序
安全性：HTML转义，防止XSS攻击
可扩展性：模块化设计，易于扩展新功能
💡 使用注意事项
标签配置：确保PC成员正确设置轨道标签
权限检查：调用用户需要有足够权限查看PC成员
浏览器兼容：需要支持ES5+和CSS3的现代浏览器
您的HotCRP系统现在拥有了一个智能、高效的PC成员选择器，将显著提升大型会议中审稿人分配的效率！