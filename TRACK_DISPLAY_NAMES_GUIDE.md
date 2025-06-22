# HotCRP 轨道选择器显示名称增强指南

## 🎯 功能概述

这个增强功能解决了 HotCRP 分层主题选择中轨道下拉菜单的用户体验问题。现在作者在投稿时看到的是完整的轨道名称，而不是系统内部的短标签。

## ✅ 已实现的功能

### 1. 显示完整轨道名称
- **之前**: 下拉菜单显示 `cloud-edge`, `wsmc` 等系统标签
- **现在**: 下拉菜单显示 `Cloud & Edge Computing (云计算与边缘计算)`, `Wireless Sensing & Mobile Computing (无线感知与移动计算)` 等完整名称

### 2. 保持后端兼容性
- **HTML `value` 属性**: 仍然使用系统标签（如 `cloud-edge`）
- **显示文本**: 使用完整的轨道名称
- **数据处理**: 后端接收和存储的仍然是系统标签，保持向后兼容

### 3. 灵活的映射机制
- 如果轨道有映射配置，显示完整名称
- 如果轨道没有映射配置，显示原系统标签（作为后备方案）

## 🔧 核心修改说明

### 修改文件
`hotcrp/hotcrp-docker-compose/app/src/options/o_topics.php`

### 修改位置
`Topics_PaperOption` 类中的 `print_hierarchical_web_edit` 方法

### 关键代码变更

#### 1. 添加映射数组
```php
$track_display_names = [
    'cloud-edge' => 'Cloud & Edge Computing (云计算与边缘计算)',
    'wsmc' => 'Wireless Sensing & Mobile Computing (无线感知与移动计算)',
    // ... 更多映射
];
```

#### 2. 修改选项生成逻辑
```php
// 之前的代码
foreach ($tracks as $track) {
    echo '<option value="', htmlspecialchars($track), '">', htmlspecialchars($track), '</option>';
}

// 修改后的代码
foreach ($tracks as $track_tag) {
    $display_name = $track_display_names[$track_tag] ?? $track_tag;
    echo '<option value="', htmlspecialchars($track_tag), '">', htmlspecialchars($display_name), '</option>';
}
```

## 📝 如何添加新的轨道映射

### 方法1: 直接修改映射数组
在 `$track_display_names` 数组中添加新的映射关系：

```php
$track_display_names = [
    // 现有映射...
    'your-track-tag' => 'Your Complete Track Name (您的完整轨道名称)',
    'another-track' => 'Another Track Full Name (另一个轨道全称)',
];
```

### 方法2: 推荐的映射规范
建议使用以下格式来保持一致性：

```php
'system-tag' => 'English Name (中文名称)',
```

**示例：**
```php
'quantum-computing' => 'Quantum Computing & Information (量子计算与信息)',
'robotics' => 'Robotics & Autonomous Systems (机器人与自主系统)',
'bioinformatics' => 'Bioinformatics & Computational Biology (生物信息学与计算生物学)',
```

## 🧪 测试验证

### 1. 功能测试步骤
1. 访问投稿页面（新投稿或编辑现有投稿）
2. 查看"主要轨道 (Main Track)"下拉菜单
3. 确认显示的是完整轨道名称
4. 选择一个轨道并查看主题是否正确显示
5. 提交表单并确认后端数据正确

### 2. 验证点
- ✅ 下拉菜单显示完整轨道名称
- ✅ 选择轨道后相关主题正确显示
- ✅ 表单提交后数据库存储的是系统标签
- ✅ 编辑已有投稿时轨道选择状态正确

### 3. 边界情况测试
- 测试没有映射配置的轨道（应显示原标签）
- 测试特殊字符（&, 空格等）的处理
- 测试多语言字符的显示

## 🔍 故障排除

### 问题1: 轨道名称没有变化
**可能原因**: 
- PHP 缓存问题
- 轨道标签与映射数组中的键不匹配

**解决方案**:
```bash
# 清理 PHP OpCache（如果启用）
sudo systemctl reload php-fpm
# 或重启 web 服务器
sudo systemctl restart nginx
```

### 问题2: 某些轨道显示系统标签
**原因**: 这些轨道在映射数组中没有配置

**解决方案**: 在 `$track_display_names` 数组中添加相应的映射

### 问题3: 轨道选择后主题不显示
**原因**: JavaScript 逻辑依赖的仍然是系统标签

**解决方案**: 这是正常的，因为 `value` 属性仍使用系统标签，JavaScript 逻辑不受影响

## 📈 扩展建议

### 1. 外部配置文件
考虑将映射关系移到外部配置文件：

```php
// config/track_display_names.php
return [
    'cloud-edge' => 'Cloud & Edge Computing (云计算与边缘计算)',
    // ... 其他映射
];
```

然后在代码中引用：
```php
$track_display_names = include __DIR__ . '/../../config/track_display_names.php';
```

### 2. 管理界面支持
未来可以考虑在 HotCRP 管理界面中添加轨道显示名称的配置功能。

### 3. 多语言支持
根据用户语言偏好显示不同语言的轨道名称。

## 📋 当前配置的轨道映射

以下是当前已配置的轨道映射关系：

| 系统标签 | 完整显示名称 |
|----------|-------------|
| `cloud-edge` | Cloud & Edge Computing (云计算与边缘计算) |
| `wsmc` | Wireless Sensing & Mobile Computing (无线感知与移动计算) |
| `ii-internet` | Industrial Informatics & Industrial Internet (工业信息学与工业互联网) |
| `infosec` | Information Security (信息安全) |
| `ai-ml` | Artificial Intelligence & Machine Learning (人工智能与机器学习) |
| `iot-systems` | Internet of Things & Smart Systems (物联网与智能系统) |
| `big-data` | Big Data & Data Analytics (大数据与数据分析) |
| `blockchain` | Blockchain & Distributed Systems (区块链与分布式系统) |
| `hci-ui` | Human-Computer Interaction & User Interface (人机交互与用户界面) |
| `networks` | Computer Networks & Communications (计算机网络与通信) |
| `systems` | Computer Systems & Architecture (计算机系统与架构) |
| `software-eng` | Software Engineering & Development (软件工程与开发) |
| `theory` | Theoretical Computer Science (理论计算机科学) |
| `graphics` | Computer Graphics & Visualization (计算机图形学与可视化) |
| `multimedia` | Multimedia & Signal Processing (多媒体与信号处理) |

## 🚀 部署注意事项

1. **备份**: 修改前请备份 `src/options/o_topics.php` 文件
2. **测试环境**: 建议先在测试环境中验证功能
3. **权限**: 确保 web 服务器对修改的文件有适当权限
4. **缓存**: 部署后清理相关缓存

---

**修改完成日期**: {当前日期}  
**版本**: 1.0.0  
**状态**: ✅ 已实现并测试 