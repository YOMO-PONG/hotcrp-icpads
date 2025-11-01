<?php
// 直接在数据库层面复制 Paper #127 到新的编号
require_once(__DIR__ . "/src/init.php");

$conf = initialize_conf();

// 获取管理员用户
$result = $conf->qe("SELECT contactId FROM ContactInfo WHERE roles!=0 AND (roles&" . Contact::ROLE_ADMIN . ")!=0 LIMIT 1");
if (!$result || !($row = $result->fetch_row())) {
    fwrite(STDERR, "错误：找不到管理员用户\n");
    exit(1);
}

$user = $conf->user_by_id($row[0]);
if (!$user || !$user->privChair) {
    fwrite(STDERR, "错误：需要管理员权限\n");
    exit(1);
}

$source_paper_id = 127;

echo "开始复制 Paper #{$source_paper_id} 到新编号...\n\n";

// 1. 检查原始论文是否存在
$source_paper = $conf->qe("SELECT * FROM Paper WHERE paperId=?", $source_paper_id);
if (!$source_paper || !($paper_data = $source_paper->fetch_assoc())) {
    fwrite(STDERR, "错误：Paper #{$source_paper_id} 不存在\n");
    exit(1);
}

echo "✓ 找到原始论文: {$paper_data['title']}\n";
echo "  提交时间: " . date('Y-m-d H:i:s', $paper_data['timeSubmitted']) . "\n\n";

echo "准备创建新论文副本...\n";
echo "请确认要继续 (yes/no): ";
$confirm = trim(fgets(STDIN));

if (strtolower($confirm) !== 'yes' && strtolower($confirm) !== 'y') {
    echo "已取消\n";
    exit(0);
}

echo "\n开始复制...\n";
echo str_repeat("=", 60) . "\n";

// 开始事务
$conf->qe("BEGIN");

try {
    // 2. 复制主Paper表
    echo "\n[1/11] 正在复制主论文记录...\n";
    unset($paper_data['paperId']); // 移除paperId，让MySQL自动生成新的
    
    $cols = array_keys($paper_data);
    $placeholders = array_fill(0, count($cols), '?');
    
    $conf->qe("INSERT INTO Paper (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")",
        ...array_values($paper_data));
    
    $new_paper_id = $conf->dblink->insert_id;
    echo "✓ 新论文已创建: Paper #{$new_paper_id}\n";
    
    // 3. 复制标签
    echo "\n[2/11] 正在复制标签...\n";
    $result = $conf->qe("SELECT tag, tagIndex FROM PaperTag WHERE paperId=?", $source_paper_id);
    $tags_copied = 0;
    while ($row = $result->fetch_assoc()) {
        $conf->qe("INSERT INTO PaperTag (paperId, tag, tagIndex) VALUES (?, ?, ?)",
            $new_paper_id, $row['tag'], $row['tagIndex']);
        $tags_copied++;
        echo "  ✓ 复制标签: {$row['tag']}\n";
    }
    if ($tags_copied === 0) {
        echo "  (没有标签需要复制)\n";
    } else {
        echo "✓ 已复制 {$tags_copied} 个标签\n";
    }
    
    // 4. 复制冲突关系
    echo "\n[3/11] 正在复制冲突关系...\n";
    $result = $conf->qe("SELECT contactId, conflictType FROM PaperConflict WHERE paperId=?", $source_paper_id);
    $conflicts_copied = 0;
    while ($row = $result->fetch_assoc()) {
        $conf->qe("INSERT INTO PaperConflict (paperId, contactId, conflictType) VALUES (?, ?, ?)",
            $new_paper_id, $row['contactId'], $row['conflictType']);
        $conflicts_copied++;
    }
    echo "✓ 已复制 {$conflicts_copied} 个冲突关系\n";
    
    // 5. 复制主题
    echo "\n[4/11] 正在复制主题...\n";
    $result = $conf->qe("SELECT topicId FROM PaperTopic WHERE paperId=?", $source_paper_id);
    $topics_copied = 0;
    while ($row = $result->fetch_assoc()) {
        $conf->qe("INSERT INTO PaperTopic (paperId, topicId) VALUES (?, ?)",
            $new_paper_id, $row['topicId']);
        $topics_copied++;
    }
    if ($topics_copied === 0) {
        echo "  (没有主题需要复制)\n";
    } else {
        echo "✓ 已复制 {$topics_copied} 个主题\n";
    }
    
    // 6. 复制选项数据
    echo "\n[5/11] 正在复制选项数据...\n";
    $result = $conf->qe("SELECT optionId, value, data, dataOverflow FROM PaperOption WHERE paperId=?", $source_paper_id);
    $options_copied = 0;
    while ($row = $result->fetch_assoc()) {
        $conf->qe("INSERT INTO PaperOption (paperId, optionId, value, data, dataOverflow) VALUES (?, ?, ?, ?, ?)",
            $new_paper_id, $row['optionId'], $row['value'], $row['data'], $row['dataOverflow']);
        $options_copied++;
    }
    if ($options_copied === 0) {
        echo "  (没有选项需要复制)\n";
    } else {
        echo "✓ 已复制 {$options_copied} 个选项\n";
    }
    
    // 7. 复制文件存储
    echo "\n[6/11] 正在复制文件存储...\n";
    $result = $conf->qe("SELECT * FROM PaperStorage WHERE paperId=?", $source_paper_id);
    $storage_copied = 0;
    $old_to_new_storage = [];
    
    while ($row = $result->fetch_assoc()) {
        $old_storage_id = $row['paperStorageId'];
        unset($row['paperStorageId']); // 让系统自动生成新的
        $row['paperId'] = $new_paper_id;
        
        $cols = array_keys($row);
        $placeholders = array_fill(0, count($cols), '?');
        $conf->qe("INSERT INTO PaperStorage (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")",
            ...array_values($row));
        
        $new_storage_id = $conf->dblink->insert_id;
        $old_to_new_storage[$old_storage_id] = $new_storage_id;
        $storage_copied++;
        echo "  ✓ 复制文件: storageId {$old_storage_id} → {$new_storage_id}\n";
    }
    
    if ($storage_copied === 0) {
        echo "  (没有文件需要复制)\n";
    } else {
        echo "✓ 已复制 {$storage_copied} 个文件\n";
    }
    
    // 8. 更新新论文的paperStorageId引用
    if (!empty($old_to_new_storage)) {
        echo "\n[7/11] 正在更新文件引用...\n";
        if ($paper_data['paperStorageId'] && isset($old_to_new_storage[$paper_data['paperStorageId']])) {
            $new_storage_id = $old_to_new_storage[$paper_data['paperStorageId']];
            $conf->qe("UPDATE Paper SET paperStorageId=? WHERE paperId=?", $new_storage_id, $new_paper_id);
            echo "✓ 已更新主文件引用: {$new_storage_id}\n";
        }
        
        if ($paper_data['finalPaperStorageId'] && isset($old_to_new_storage[$paper_data['finalPaperStorageId']])) {
            $new_final_storage_id = $old_to_new_storage[$paper_data['finalPaperStorageId']];
            $conf->qe("UPDATE Paper SET finalPaperStorageId=? WHERE paperId=?", $new_final_storage_id, $new_paper_id);
            echo "✓ 已更新终稿文件引用: {$new_final_storage_id}\n";
        }
    } else {
        echo "\n[7/11] 跳过文件引用更新 (无文件)\n";
    }
    
    // 9. 复制评论
    echo "\n[8/11] 正在复制评论...\n";
    $result = $conf->qe("SELECT * FROM PaperComment WHERE paperId=?", $source_paper_id);
    $comments_copied = 0;
    
    while ($row = $result->fetch_assoc()) {
        unset($row['commentId']); // 让系统自动生成新的commentId
        $row['paperId'] = $new_paper_id;
        
        $cols = array_keys($row);
        $placeholders = array_fill(0, count($cols), '?');
        $conf->qe("INSERT INTO PaperComment (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")",
            ...array_values($row));
        $comments_copied++;
    }
    
    if ($comments_copied === 0) {
        echo "  (没有评论需要复制)\n";
    } else {
        echo "✓ 已复制 {$comments_copied} 条评论\n";
    }
    
    // 10. 复制审稿记录
    echo "\n[9/11] 正在复制审稿记录...\n";
    $result = $conf->qe("SELECT * FROM PaperReview WHERE paperId=?", $source_paper_id);
    $reviews_copied = 0;
    
    while ($row = $result->fetch_assoc()) {
        unset($row['reviewId']); // 让系统自动生成新的reviewId
        $row['paperId'] = $new_paper_id;
        
        $cols = array_keys($row);
        $placeholders = array_fill(0, count($cols), '?');
        $conf->qe("INSERT INTO PaperReview (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")",
            ...array_values($row));
        $reviews_copied++;
    }
    
    if ($reviews_copied === 0) {
        echo "  (没有审稿记录需要复制)\n";
    } else {
        echo "✓ 已复制 {$reviews_copied} 条审稿记录\n";
    }
    
    // 11. 复制观察者
    echo "\n[10/11] 正在复制观察者...\n";
    $result = $conf->qe("SELECT contactId, watch FROM PaperWatch WHERE paperId=?", $source_paper_id);
    $watches_copied = 0;
    
    while ($row = $result->fetch_assoc()) {
        $conf->qe("INSERT INTO PaperWatch (paperId, contactId, watch) VALUES (?, ?, ?)",
            $new_paper_id, $row['contactId'], $row['watch']);
        $watches_copied++;
    }
    
    if ($watches_copied === 0) {
        echo "  (没有观察者需要复制)\n";
    } else {
        echo "✓ 已复制 {$watches_copied} 个观察者\n";
    }
    
    // 12. 记录日志
    echo "\n[11/11] 正在记录日志...\n";
    $user->log_activity("Duplicated paper #{$source_paper_id} to #{$new_paper_id}", $new_paper_id);
    echo "✓ 已记录日志\n";
    
    // 提交事务
    $conf->qe("COMMIT");
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "✓✓✓ 复制完成！✓✓✓\n";
    echo str_repeat("=", 60) . "\n";
    echo "\n原始论文: Paper #{$source_paper_id}\n";
    echo "新论文: Paper #{$new_paper_id}\n";
    echo "\n您现在可以:\n";
    echo "1. 访问网站查看新论文 Paper #{$new_paper_id}\n";
    echo "2. 使用新的 Paper #{$new_paper_id} 重新注册ECF\n";
    echo "3. 如果需要，可以考虑撤回原始的 Paper #{$source_paper_id}\n";
    echo "\n注意: 新论文保留了原论文的所有数据，包括:\n";
    echo "  - 标题、摘要、作者信息\n";
    echo "  - 提交的PDF文件\n";
    echo "  - 所有标签\n";
    echo "  - 冲突关系\n";
    echo "  - 审稿记录和评论\n";
    echo "  - 提交状态\n";
    echo "\n";
    
} catch (Exception $e) {
    // 回滚事务
    $conf->qe("ROLLBACK");
    fwrite(STDERR, "\n错误：复制过程中出现异常\n");
    fwrite(STDERR, "错误信息: " . $e->getMessage() . "\n");
    fwrite(STDERR, "堆栈跟踪:\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

exit(0);

