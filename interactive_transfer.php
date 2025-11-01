<?php
/**
 * 交互式批量转移工具
 * 
 * 使用方法：
 * docker-compose exec -T php php /srv/www/api/interactive_transfer.php
 */

require_once(__DIR__ . "/src/init.php");

$conf = initialize_conf();
$user = $conf->root_user();

if (!$user || !$user->privChair) {
    $result = $conf->qe("SELECT contactId FROM ContactInfo WHERE (roles & " . Contact::ROLE_ADMIN . ") != 0 LIMIT 1");
    if ($result && ($row = $result->fetch_row())) {
        $user = $conf->user_by_id($row[0]);
    }
}

if (!$user || !$user->privChair) {
    fwrite(STDERR, "错误：需要管理员权限\n");
    exit(1);
}

echo "==========================================\n";
echo "交互式批量转移Papers Submission Class\n";
echo "==========================================\n";
echo "管理员: {$user->email}\n\n";

// 显示可用的submission classes
echo "可用的 Submission Classes:\n";
$submission_rounds = $conf->submission_round_list();
$class_map = [];
$i = 1;

foreach ($submission_rounds as $sr) {
    $display = $sr->tag . ($sr->unnamed ? " (默认)" : "");
    echo "  [{$i}] {$display}\n";
    $class_map[$i] = $sr;
    $i++;
}

echo "\n选择目标 Submission Class (输入编号): ";
$class_choice = trim(fgets(STDIN));

if (!isset($class_map[(int)$class_choice])) {
    echo "无效的选择\n";
    exit(1);
}

$target_sr = $class_map[(int)$class_choice];
echo "已选择: {$target_sr->tag}\n\n";

// 输入paper IDs
echo "请输入要转移的Paper IDs（多个ID用逗号、空格或换行分隔）:\n";
echo "完成后输入 'done' 或按Ctrl+D:\n";

$paper_ids = [];
while ($line = fgets(STDIN)) {
    $line = trim($line);
    if (strtolower($line) === 'done') {
        break;
    }
    
    // 分割输入的IDs
    $ids = preg_split('/[\s,]+/', $line, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($ids as $id) {
        if (is_numeric($id)) {
            $paper_ids[] = (int)$id;
        }
    }
}

if (empty($paper_ids)) {
    echo "没有输入有效的Paper IDs\n";
    exit(1);
}

$paper_ids = array_unique($paper_ids);
sort($paper_ids);

echo "\n将转移以下 " . count($paper_ids) . " 个papers到 '{$target_sr->tag}':\n";
echo implode(', ', $paper_ids) . "\n\n";

echo "确认执行? (yes/no): ";
$confirm = trim(fgets(STDIN));

if (strtolower($confirm) !== 'yes' && strtolower($confirm) !== 'y') {
    echo "已取消\n";
    exit(0);
}

echo "\n开始转移...\n";
echo "==========================================\n\n";

$success_count = 0;
$skip_count = 0;
$error_count = 0;

foreach ($paper_ids as $index => $paper_id) {
    $progress = $index + 1;
    $total = count($paper_ids);
    
    echo "[{$progress}/{$total}] Paper #{$paper_id}... ";
    
    // 加载paper
    $prow = $conf->paper_by_id($paper_id, $user, ["tags" => true]);
    
    if (!$prow) {
        echo "✗ Paper不存在\n";
        $error_count++;
        continue;
    }
    
    $old_sr = $prow->submission_round();
    
    // 检查是否已经在目标class中
    if ($old_sr->tag === $target_sr->tag) {
        echo "⊙ 已在目标class中\n";
        $skip_count++;
        continue;
    }
    
    // 构建CSV指令
    $csv_lines = ["paper,action,tag"];
    
    if (!$old_sr->unnamed) {
        $csv_lines[] = "{$paper_id},tag,{$old_sr->tag}#clear";
    }
    
    if (!$target_sr->unnamed) {
        $csv_lines[] = "{$paper_id},tag,{$target_sr->tag}";
    }
    
    // 执行转移
    $assigner = new AssignmentSet($user);
    $assigner->enable_papers($prow);
    $assigner->parse(join("\n", $csv_lines));
    
    if ($assigner->execute()) {
        echo "✓ 成功 ({$old_sr->tag} → {$target_sr->tag})\n";
        $success_count++;
        
        $user->log_activity(
            "Paper #{$paper_id} transferred to '{$target_sr->tag}'",
            $paper_id
        );
    } else {
        $messages = $assigner->message_list();
        $error_msg = !empty($messages) ? $messages[0]->message : '未知错误';
        echo "✗ 失败: {$error_msg}\n";
        $error_count++;
    }
}

// 输出汇总
echo "\n==========================================\n";
echo "批量转移完成\n";
echo "==========================================\n";
echo "总计: " . count($paper_ids) . " 条\n";
echo "成功: {$success_count} 条\n";
echo "跳过: {$skip_count} 条\n";
echo "失败: {$error_count} 条\n";

