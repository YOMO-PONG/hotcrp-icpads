<?php
/**
 * 批量转移Papers到不同的Submission Classes
 * 
 * 使用方法：
 * 1. 创建CSV文件，格式：paper_id,submission_class
 * 2. 运行：php batch_transfer_papers.php papers.csv
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
echo "批量转移Papers Submission Class 工具\n";
echo "==========================================\n";
echo "管理员: {$user->email}\n\n";

// 检查命令行参数
if ($argc < 2) {
    echo "用法：php batch_transfer_papers.php <csv_file>\n\n";
    echo "CSV文件格式示例：\n";
    echo "paper_id,submission_class\n";
    echo "123,Poster/Workshop/PhD-Forum\n";
    echo "456,Regular Paper\n";
    echo "789,Poster/Workshop/PhD-Forum\n\n";
    echo "或者使用交互模式：php batch_transfer_papers.php\n";
    exit(1);
}

$csv_file = $argv[1];

if (!file_exists($csv_file)) {
    fwrite(STDERR, "错误：文件不存在: {$csv_file}\n");
    exit(1);
}

// 读取CSV文件
$transfers = [];
$line_num = 0;
$handle = fopen($csv_file, 'r');

while (($data = fgetcsv($handle)) !== false) {
    $line_num++;
    
    // 跳过标题行
    if ($line_num === 1 && ($data[0] === 'paper_id' || $data[0] === 'paperId')) {
        continue;
    }
    
    // 跳过空行或注释行
    if (empty($data[0]) || strpos($data[0], '#') === 0) {
        continue;
    }
    
    $paper_id = trim($data[0]);
    $submission_class = isset($data[1]) ? trim($data[1]) : '';
    
    if (!is_numeric($paper_id) || empty($submission_class)) {
        echo "⚠ 警告：第 {$line_num} 行格式错误，跳过\n";
        continue;
    }
    
    $transfers[] = [
        'paper_id' => (int)$paper_id,
        'submission_class' => $submission_class
    ];
}

fclose($handle);

if (empty($transfers)) {
    echo "错误：CSV文件中没有有效的转移记录\n";
    exit(1);
}

echo "从文件读取到 " . count($transfers) . " 条转移记录\n\n";

// 预检查所有submission classes是否存在
echo "预检查 submission classes...\n";
$class_cache = [];
$invalid_classes = [];

foreach ($transfers as $transfer) {
    $class_name = $transfer['submission_class'];
    
    if (!isset($class_cache[$class_name])) {
        $sr = $conf->submission_round_by_tag($class_name, true);
        if ($sr) {
            $class_cache[$class_name] = $sr;
            echo "✓ '{$class_name}' 存在\n";
        } else {
            $class_cache[$class_name] = false;
            $invalid_classes[] = $class_name;
            echo "✗ '{$class_name}' 不存在\n";
        }
    }
}

if (!empty($invalid_classes)) {
    echo "\n错误：以下 submission classes 不存在:\n";
    foreach (array_unique($invalid_classes) as $class) {
        echo "  - {$class}\n";
    }
    echo "\n可用的 submission classes:\n";
    foreach ($conf->submission_round_list() as $sr) {
        echo "  - {$sr->tag}" . ($sr->unnamed ? " (默认)" : "") . "\n";
    }
    exit(1);
}

echo "\n开始批量转移...\n";
echo "==========================================\n\n";

$success_count = 0;
$skip_count = 0;
$error_count = 0;
$results = [];

foreach ($transfers as $index => $transfer) {
    $paper_id = $transfer['paper_id'];
    $class_name = $transfer['submission_class'];
    $progress = $index + 1;
    $total = count($transfers);
    
    echo "[{$progress}/{$total}] Paper #{$paper_id} → '{$class_name}'... ";
    
    // 加载paper
    $prow = $conf->paper_by_id($paper_id, $user, ["tags" => true]);
    
    if (!$prow) {
        echo "✗ Paper不存在\n";
        $error_count++;
        $results[] = ['paper_id' => $paper_id, 'status' => 'error', 'message' => 'Paper不存在'];
        continue;
    }
    
    $old_sr = $prow->submission_round();
    $new_sr = $class_cache[$class_name];
    
    // 检查是否已经在目标class中
    if ($old_sr->tag === $new_sr->tag) {
        echo "⊙ 已在目标class中\n";
        $skip_count++;
        $results[] = ['paper_id' => $paper_id, 'status' => 'skip', 'message' => '已在目标class中'];
        continue;
    }
    
    // 构建CSV指令
    $csv_lines = ["paper,action,tag"];
    
    // 移除旧tag
    if (!$old_sr->unnamed) {
        $csv_lines[] = "{$paper_id},tag,{$old_sr->tag}#clear";
    }
    
    // 添加新tag
    if (!$new_sr->unnamed) {
        $csv_lines[] = "{$paper_id},tag,{$new_sr->tag}";
    }
    
    // 执行转移
    $assigner = new AssignmentSet($user);
    $assigner->enable_papers($prow);
    $assigner->parse(join("\n", $csv_lines));
    
    if ($assigner->execute()) {
        echo "✓ 成功\n";
        $success_count++;
        $results[] = [
            'paper_id' => $paper_id,
            'status' => 'success',
            'old_class' => $old_sr->tag ?: '(默认)',
            'new_class' => $new_sr->tag
        ];
        
        // 记录日志
        $user->log_activity(
            "Paper #{$paper_id} transferred to '{$new_sr->tag}'",
            $paper_id
        );
    } else {
        $messages = $assigner->message_list();
        $error_msg = !empty($messages) ? $messages[0]->message : '未知错误';
        echo "✗ 失败: {$error_msg}\n";
        $error_count++;
        $results[] = ['paper_id' => $paper_id, 'status' => 'error', 'message' => $error_msg];
    }
}

// 输出汇总
echo "\n==========================================\n";
echo "批量转移完成\n";
echo "==========================================\n";
echo "总计: " . count($transfers) . " 条\n";
echo "成功: {$success_count} 条\n";
echo "跳过: {$skip_count} 条\n";
echo "失败: {$error_count} 条\n";

// 生成结果报告
$report_file = "transfer_report_" . date('YmdHis') . ".csv";
$report_path = __DIR__ . "/" . $report_file;
$fp = fopen($report_path, 'w');
fputcsv($fp, ['paper_id', 'status', 'old_class', 'new_class', 'message']);

foreach ($results as $result) {
    fputcsv($fp, [
        $result['paper_id'],
        $result['status'],
        $result['old_class'] ?? '',
        $result['new_class'] ?? '',
        $result['message'] ?? ''
    ]);
}

fclose($fp);

echo "\n详细报告已保存到: {$report_file}\n";
exit($error_count > 0 ? 1 : 0);

