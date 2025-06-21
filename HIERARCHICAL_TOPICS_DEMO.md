# HotCRP 分层主题选择功能演示

## 功能概述

本功能为HotCRP会议管理系统添加了分层主题选择机制，允许作者首先选择高级"轨道"(Track)，然后从该轨道的相关主题中进行选择。

## 使用方法

### 1. 在管理界面配置主题

访问Settings > Topics页面，使用以下格式定义主题：

```
Track名称 > 主题名称
```

#### 示例配置：

```
Networking > TCP/IP
Networking > Wireless Communication  
Networking > Network Security
Networking > IoT Networks

AI > Reinforcement Learning
AI > Computer Vision
AI > Natural Language Processing
AI > Machine Learning Theory

Systems > Operating Systems
Systems > Distributed Systems
Systems > Database Systems
Systems > Performance Optimization

Security > Cryptography
Security > Web Security
Security > Mobile Security
Security > Privacy Protection
```

### 2. 作者体验

当作者提交论文时，他们会看到：

1. **主要轨道 (Main Track)** 下拉菜单
2. 选择轨道后，**相关主题 (Topics)** 区域会动态显示该轨道下的所有主题复选框
3. 作者可以从显示的主题中选择一个或多个相关主题

### 3. 兼容性

- 支持传统的冒号格式主题（如"Systems: Correctness"）
- 新旧格式可以混合使用
- 现有数据不会受到影响

## 技术实现

- **后端**：扩展了TopicSet类以支持Track-Topic映射
- **前端**：添加了JavaScript动态交互逻辑
- **数据存储**：使用现有的主题数据结构，无需数据库变更

## 管理员注意事项

1. 在Topics设置页面，系统会自动解析`>`符号分隔的格式
2. Track名称会自动去重并按字母顺序排列
3. 可以随时在管理界面修改或删除主题
4. 支持PC委员的主题兴趣设置和搜索功能

## 预期效果

- 简化作者的主题选择流程
- 减少主题选择错误
- 提高主题分类的一致性
- 便于PC委员按轨道进行论文分配 