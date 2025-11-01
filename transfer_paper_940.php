<?php
// 转移 Paper #940 到 Poster/Workshop/PhD-Forum submission class
require_once(__DIR__ . "/src/init.php");

$conf = initialize_conf();

// 尝试使用管理员账户
// 方法1: 使用root用户
$user = $conf->root_user();

// 如果root用户不可用，尝试获取任何管理员
if (!$user || !$user->privChair) {
    $result = $conf->qe("SELECT contactId FROM ContactInfo WHERE (roles & " . Contact::ROLE_ADMIN . ") != 0 LIMIT 1");
    if ($result && ($row = $result->fetch_row())) {
        $user = $conf->user_by_id($row[0]);
    }
}

// 确保用户有管理员权限
if (!$user || !$user->privChair) {
    fwrite(STDERR, "错误：需要管理员权限，无法找到管理员账户\n");
    exit(1);
}

echo "使用管理员账户: {$user->email}\n\n";

/**
 * 将单个paper转移到新的submission class
 */
function transfer_paper_submission_class(Contact $user, $paperId, $newClassName) {
    $conf = $user->conf;
    
    echo "开始转移 Paper #{$paperId} 到 submission class '{$newClassName}'...\n";
    
    // 1. 加载paper
    $prow = $conf->paper_by_id($paperId, $user, [
        "topics" => true,
        "options" => true,
        "tags" => true
    ]);
    
    if (!$prow) {
        return [
            'success' => false,
            'message' => "Paper #{$paperId} 不存在"
        ];
    }
    
    echo "✓ Paper #$paperId 已加载: {$prow->title}\n";
    
    // 2. 验证目标submission class是否存在
    $new_sr = $conf->submission_round_by_tag($newClassName, true);
    if (!$new_sr) {
        return [
            'success' => false,
            'message' => "Submission class '{$newClassName}' 不存在"
        ];
    }
    
    echo "✓ 目标 submission class '{$newClassName}' 存在\n";
    
    // 3. 获取当前submission class
    $old_sr = $prow->submission_round();
    $old_tag = $old_sr->tag ?: "(默认)";
    
    echo "  当前 submission class: {$old_tag}\n";
    echo "  目标 submission class: {$new_sr->tag}\n";
    
    // 4. 检查是否已经在目标class中
    if ($old_sr->tag === $new_sr->tag) {
        return [
            'success' => true,
            'message' => "Paper #{$paperId} 已经在 submission class '{$newClassName}' 中"
        ];
    }
    
    // 5. 构建CSV格式的赋值指令
    $csv_lines = ["paper,action,tag"];
    
    // 移除旧的submission class tag（如果不是默认的unnamed class）
    if (!$old_sr->unnamed) {
        $csv_lines[] = "{$paperId},tag,{$old_sr->tag}#clear";
        echo "  → 将移除旧tag: {$old_sr->tag}\n";
    }
    
    // 添加新的submission class tag（如果不是默认的unnamed class）
    if (!$new_sr->unnamed) {
        $csv_lines[] = "{$paperId},tag,{$new_sr->tag}";
        echo "  → 将添加新tag: {$new_sr->tag}\n";
    }
    
    // 6. 创建AssignmentSet并执行
    echo "\n正在执行转移操作...\n";
    
    $assigner = new AssignmentSet($user);
    $assigner->enable_papers($prow);
    $assigner->parse(join("\n", $csv_lines));
    
    // 检查是否有解析错误
    if ($assigner->has_error()) {
        $messages = $assigner->message_list();
        $error_msgs = [];
        foreach ($messages as $msg) {
            if ($msg->status >= 2) { // 错误级别
                $error_msgs[] = $msg->message;
            }
        }
        return [
            'success' => false,
            'message' => '解析失败: ' . implode('; ', $error_msgs),
            'messages' => $messages
        ];
    }
    
    // 7. 执行赋值操作
    $success = $assigner->execute();
    
    if ($success) {
        // 重新加载paper的tags
        $prow->load_tags();
        
        // 记录操作日志
        $user->log_activity(
            "Paper #{$paperId} transferred from '{$old_tag}' to '{$new_sr->tag}'",
            $paperId
        );
        
        echo "✓ 转移成功！\n";
        
        // 验证转移结果
        $prow_new = $conf->paper_by_id($paperId, $user, ["tags" => true]);
        $new_check_sr = $prow_new->submission_round();
        echo "✓ 验证: 当前 submission class = {$new_check_sr->tag}\n";
        
        return [
            'success' => true,
            'message' => "成功将 Paper #{$paperId} 从 '{$old_tag}' 转移到 '{$new_sr->tag}'",
            'old_class' => $old_tag,
            'new_class' => $new_sr->tag
        ];
    } else {
        $messages = $assigner->message_list();
        $error_msgs = [];
        foreach ($messages as $msg) {
            $error_msgs[] = $msg->message;
        }
        
        echo "✗ 转移失败\n";
        foreach ($error_msgs as $msg) {
            echo "  错误: {$msg}\n";
        }
        
        return [
            'success' => false,
            'message' => '转移失败: ' . implode('; ', $error_msgs),
            'messages' => $messages
        ];
    }
}

// 执行转移
echo "==========================================\n";
echo "Paper #940 Submission Class 转移工具\n";
echo "==========================================\n\n";

$paperId = 940;
$newClassName = "Poster/Workshop/PhD-Forum";

$result = transfer_paper_submission_class($user, $paperId, $newClassName);

echo "\n==========================================\n";
echo "执行结果\n";
echo "==========================================\n";
echo "状态: " . ($result['success'] ? "✓ 成功" : "✗ 失败") . "\n";
echo "消息: {$result['message']}\n";

if ($result['success']) {
    if (isset($result['old_class']) && isset($result['new_class'])) {
        echo "旧 class: {$result['old_class']}\n";
        echo "新 class: {$result['new_class']}\n";
    }
    echo "\n✓ Paper #940 已成功转移到 '{$newClassName}'\n";
    exit(0);
} else {
    echo "\n✗ 操作失败，请检查错误信息\n";
    exit(1);
}

