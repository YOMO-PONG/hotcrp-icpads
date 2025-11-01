#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
CSV 批量导入评审

CSV示例列（固定表头，按需扩展）：
paper id,审稿人姓名,审稿人邮箱,papersummary,strengths,weakness,overall merit（1-5）,reviewer expertise（1-4）,comments for author
- paper id: 论文ID
- 审稿人姓名: 评审姓名（用于外部评审自动建联系人）
- 审稿人邮箱: 评审邮箱（必填）
- overall merit（1-5）: 整体评分（1-5）
- reviewer expertise（1-4）: 专业度（1-4）
- 评审类型固定为 external（外部评审，系统会自动为非PC成员创建账户）
"""

import csv
import json
import time
import urllib.parse
import sys
from typing import Dict, Optional, Any

try:
    import requests
except ImportError:
    print("错误: 需要安装 requests 库。运行: pip install requests")
    sys.exit(1)


class ReviewCSVImporter:
    def __init__(self, dry_run: bool = True):
        self.api_token = "hct_JSbozdULAJCcNBChfuwSDhWaFRuRYKUtbscBGEuBaMxD"
        self.base_url = "http://hotcrp.treasurew.com"
        self.dry_run = dry_run

    def http_post_json(self, path: str, payload: Dict) -> Dict[str, Any]:
        """发送 JSON POST 请求"""
        url = self.base_url + path
        if self.dry_run:
            url += ("&" if "?" in path else "?") + "dry_run=1"

        headers = {
            "Authorization": f"bearer {self.api_token}",
            "Content-Type": "application/json"
        }

        try:
            response = requests.post(url, json=payload, headers=headers, timeout=60)
            response.raise_for_status()
            data = response.json()
        except requests.exceptions.RequestException as e:
            raise Exception(f"API请求失败: {path} - {str(e)}")

        # 调试：显示 API 返回
        if "reviewerEmail" in payload or "reviewer" in payload:
            json_str = json.dumps(payload, ensure_ascii=False)
            print(f"  🔍 API调试: {url}")
            print(f"  📤 请求: {json_str[:500]}{'...' if len(json_str) > 500 else ''}")
            print(f"  📥 完整响应: {json.dumps(data, ensure_ascii=False, indent=2)}")
            # 检查是否有错误信息
            if "message_list" in data or "error" in data or "errors" in data:
                error_info = data.get("message_list") or data.get("error") or data.get("errors")
                print(f"  ⚠️  警告或错误: {json.dumps(error_info, ensure_ascii=False)}")
    
    def http_post_form_review(self, path: str, params: Dict) -> Dict[str, Any]:
        """发送表单编码的 POST 请求（用于 review API，带调试输出）"""
        url = self.base_url + path
        if self.dry_run:
            url += ("&" if "?" in path else "?") + "dry_run=1"

        headers = {
            "Authorization": f"bearer {self.api_token}",
            "Content-Type": "application/x-www-form-urlencoded",
            "Accept": "application/json"  # 请求JSON响应
        }

        try:
            response = requests.post(url, data=params, headers=headers, timeout=60, allow_redirects=False)
            # 如果是重定向，可能提交成功了
            if response.status_code in [301, 302, 303, 307, 308]:
                result = {"ok": True, "data": {"message": "提交成功（重定向）", "redirect": response.headers.get("Location")}}
                print(f"  🔍 API调试: {url}")
                print(f"  📤 请求参数: {len(params)} 个参数")
                print(f"  📥 响应: 重定向到 {result['data'].get('redirect')}")
                return result
            
            response.raise_for_status()
            
            # 尝试解析JSON，如果不是JSON，可能是HTML响应
            content_type = response.headers.get("Content-Type", "").lower()
            if "application/json" in content_type or response.text.strip().startswith("{"):
                data = response.json()
                # JSON响应直接返回
                result = {"ok": data.get("ok", False), "data": data}
                # 调试输出
                if "reviewerEmail" in params or "reviewer" in params:
                    important_params = ["submitreview", "savedraft", "update", "reviewerEmail", "ready", "override", "r", "edit_version", "if_vtag_match"]
                    param_list = []
                    for k in important_params:
                        if k in params:
                            param_list.append(f"{k}={urllib.parse.quote(str(params[k]))}")
                    field_params = [f"{k}={urllib.parse.quote(str(v))}" for k, v in params.items() 
                                   if k not in important_params][:3]
                    all_param_str = "&".join(param_list + field_params)
                    print(f"  🔍 API调试: {url}")
                    print(f"  📤 重要参数: {all_param_str}{'...' if len(params) > len(important_params) + 3 else ''}")
                    print(f"  📥 响应状态码: {response.status_code}")
                    print(f"  📥 JSON响应: ok={result['ok']}, message_list={json.dumps(data.get('message_list', []), ensure_ascii=False)}")
                return result
            else:
                # HTML响应，可能提交成功但返回了HTML页面
                # HotCRP的/review页面在提交后可能返回HTML而不是JSON
                # 检查响应状态码和内容来判断是否成功
                html_lower = response.text.lower()
                html_text = response.text
                
                # 检查是否有明确的错误消息
                # "paper-error"类名可能只是页面结构的一部分，需要检查实际的错误消息
                has_error_message = False
                error_indicators = [
                    "error:", "invalid", "cannot", "failed", "permission denied",
                    "you do not have permission", "access denied"
                ]
                # 检查前2000个字符中是否有错误消息
                preview_text = html_text[:2000].lower()
                for indicator in error_indicators:
                    if indicator in preview_text:
                        has_error_message = True
                        break
                
                # 检查是否有成功指示
                has_success_message = "success" in html_lower or "submitted" in html_lower or "saved" in html_lower
                
                # 如果状态码是200且没有明确的错误消息，认为可能成功了
                # 稍后会在验证阶段确认评审是否真正保存
                if response.status_code == 200:
                    if has_error_message:
                        # 有明确的错误消息
                        preview = html_text[:500] if len(html_text) > 500 else html_text
                        result = {"ok": False, "data": {"error": "HTML响应包含错误消息", "preview": preview}}
                    elif has_success_message:
                        result = {"ok": True, "data": {"message": "提交成功（HTML响应，检测到成功消息）", "html_response": True}}
                    else:
                        # 状态码200但没有明确的成功或错误消息，可能是页面正常返回
                        # 认为可能成功了，后续验证步骤会确认
                        result = {"ok": True, "data": {"message": "提交可能成功（HTML响应，状态码200）", "html_response": True, "needs_verification": True}}
                else:
                    # 其他状态码
                    preview = html_text[:500] if len(html_text) > 500 else html_text
                    result = {"ok": False, "data": {"error": f"收到HTML响应，状态码{response.status_code}", "preview": preview, "status_code": response.status_code}}
                
                # 调试输出
                if "reviewerEmail" in params or "reviewer" in params:
                    # 显示所有重要参数（不只是前10个）
                    important_params = ["submitreview", "savedraft", "update", "reviewerEmail", "ready", "override", "r", "edit_version", "if_vtag_match"]
                    param_list = []
                    for k in important_params:
                        if k in params:
                            param_list.append(f"{k}={urllib.parse.quote(str(params[k]))}")
                    # 显示字段参数（前3个）
                    field_params = [f"{k}={urllib.parse.quote(str(v))}" for k, v in params.items() 
                                   if k not in important_params][:3]
                    all_param_str = "&".join(param_list + field_params)
                    print(f"  🔍 API调试: {url}")
                    print(f"  📤 重要参数: {all_param_str}{'...' if len(params) > len(important_params) + 3 else ''}")
                    print(f"  📥 响应状态码: {response.status_code}")
                    print(f"  📥 响应: {result['data'].get('message', result['data'].get('error', '未知响应'))}")
                    
                    # 如果收到HTML响应，检查是否有错误消息
                    if result.get("data", {}).get("html_response"):
                        html_text = response.text
                        html_lower = html_text.lower()
                        
                        # 尝试提取错误消息（不管是否有paper-error类，都检查是否有实际错误）
                        import re
                        # 查找错误消息（可能在多个位置）
                        error_patterns = [
                            # 查找 revcard-feedback 中的错误
                            r'<div[^>]*class="[^"]*revcard-feedback[^"]*"[^>]*>(.*?)</div>',
                            # 查找 feedback is-error
                            r'<div[^>]*class="[^"]*feedback[^"]*is-error[^"]*"[^>]*>(.*?)</div>',
                            # 查找包含错误文本的div
                            r'<div[^>]*class="[^"]*message[^"]*error[^"]*"[^>]*>(.*?)</div>',
                            # 查找消息列表中的错误
                            r'<div[^>]*class="[^"]*message-list[^"]*"[^>]*>(.*?)</div>',
                        ]
                        
                        found_error_msg = None
                        for pattern in error_patterns:
                            matches = re.finditer(pattern, html_text, re.IGNORECASE | re.DOTALL)
                            for match in matches:
                                content = match.group(1)
                                # 移除HTML标签，获取纯文本
                                text = re.sub(r'<[^>]+>', ' ', content).strip()
                                # 清理空白字符
                                text = ' '.join(text.split())
                                if text and len(text) > 10:
                                    found_error_msg = text
                                    break
                            if found_error_msg:
                                break
                        
                        # 总是保存HTML响应以便调试（如果返回HTML且状态码200但没有明确的成功消息）
                        if response.status_code == 200 and not found_error_msg and not has_success_message:
                            import os
                            debug_dir = "debug_html_responses"
                            os.makedirs(debug_dir, exist_ok=True)
                            # 从path中提取pid和review_id
                            pid_from_path = None
                            rid_from_path = None
                            if "?p=" in path or "&p=" in path:
                                pid_match = re.search(r'[&?]p=(\d+)', path)
                                if pid_match:
                                    pid_from_path = pid_match.group(1)
                            if "?r=" in path or "&r=" in path:
                                rid_match = re.search(r'[&?]r=(\d+)', path)
                                if rid_match:
                                    rid_from_path = rid_match.group(1)
                            
                            # 从params中提取时间戳作为文件名
                            import time
                            timestamp = int(time.time())
                            debug_file = f"{debug_dir}/review_response_p{pid_from_path}_r{rid_from_path}_{timestamp}.html"
                            with open(debug_file, 'w', encoding='utf-8') as f:
                                f.write(html_text)
                            print(f"  💾 HTML响应已保存到: {debug_file} (用于调试)")
                        
                        # 如果找到了明确的错误消息，才认为是错误
                        if found_error_msg:
                            print(f"  ❌ 检测到错误消息: {found_error_msg[:300]}")
                            # 标记为失败
                            result = {"ok": False, "data": {"error": f"HTML响应包含错误: {found_error_msg[:100]}", "html_error": True}}
                        # 如果没有找到明确的错误消息，但状态码是200，可能成功（后续验证会确认）
                        elif response.status_code == 200:
                            # paper-error类可能只是CSS类名，不一定表示错误
                            # 继续后续的验证流程
                            pass
                return result
                    
        except requests.exceptions.RequestException as e:
            raise Exception(f"API请求失败: {path} - {str(e)}")

    def http_post_form(self, path: str, params: Dict) -> Dict[str, Any]:
        """发送表单编码的 POST 请求（用于 assign API）"""
        url = self.base_url + path
        if self.dry_run:
            url += ("&" if "?" in path else "?") + "dry_run=1"

        headers = {
            "Authorization": f"bearer {self.api_token}",
            "Content-Type": "application/x-www-form-urlencoded"
        }

        try:
            response = requests.post(url, data=params, headers=headers, timeout=60)
            response.raise_for_status()
            data = response.json()
        except requests.exceptions.RequestException as e:
            raise Exception(f"API请求失败: {path} - {str(e)}")

        return {"ok": data.get("ok", False), "data": data}

    def http_get(self, path: str) -> Optional[Dict]:
        """发送 GET 请求"""
        url = self.base_url + path
        headers = {
            "Authorization": f"bearer {self.api_token}"
        }

        try:
            response = requests.get(url, headers=headers, timeout=60)
            response.raise_for_status()
            return response.json()
        except requests.exceptions.RequestException:
            return None

    def ensure_assignment(self, pid: int, reviewer_email: str, reviewer_name: str = "", round_val: Optional[str] = None) -> Dict[str, Any]:
        """为指定论文与用户创建/确保评审分配，并自动接受 external 评审以跳过确认步骤"""
        pid = int(pid)
        email = reviewer_email.strip()
        name = reviewer_name.strip() if reviewer_name else ""
        round_str = round_val.strip() if round_val else ""

        # 构建 assignments 对象数组
        assignment = {
            "paper": pid,
            "action": "external",  # 使用 "external" 作为 action（外部评审）
            "email": email
        }

        # 添加可选字段
        if name:
            assignment["name"] = name
        if round_str:
            assignment["round"] = round_str

        # API 期望 assignments 参数是 JSON 字符串（form-encoded 格式）
        assignments_json = json.dumps([assignment])

        # assign API 使用 form-encoded 格式
        params = {
            "assignments": assignments_json
        }

        # 添加 p 参数到 URL
        path = f"/api/assign?p={pid}"
        assign_res = self.http_post_form(path, params)
        
        # 调试：显示分配结果
        print(f"  📋 分配结果: ok={assign_res['ok']}")
        if assign_res["ok"]:
            print(f"  ✅ 分配API调用成功")
            # 显示分配响应中的详细信息
            if "data" in assign_res and assign_res["data"]:
                if "message_list" in assign_res["data"]:
                    print(f"  📝 消息: {json.dumps(assign_res['data'].get('message_list', []), ensure_ascii=False)}")
        else:
            print(f"  ❌ 分配失败详情: {json.dumps(assign_res.get('data', {}), ensure_ascii=False)}")

        # 如果分配成功，自动接受评审以跳过确认步骤
        if assign_res["ok"]:
            # 等待更长时间确保评审分配已完全完成
            time.sleep(2.0)  # 增加到2秒
            accept_res = self.auto_accept_review(pid, email)
            # 如果接受失败，记录但继续（可能评审已经被接受了）
            if not accept_res["ok"] and not self.dry_run:
                # 再等待一下，然后重试一次
                time.sleep(1.5)
                accept_res = self.auto_accept_review(pid, email)

        return assign_res

    def auto_accept_review(self, pid: int, reviewer_email: str) -> Dict[str, Any]:
        """自动接受评审（跳过确认步骤）"""
        # 重试获取评审信息
        review_info = None
        for retry in range(3):
            review_info = self.get_review_info(pid, reviewer_email)
            # API返回的字段名是 "rid" 而不是 "reviewId"
            if review_info and ("rid" in review_info or "reviewId" in review_info):
                break
            if retry < 2:
                time.sleep(0.5)
        
        # 如果使用u参数查询失败，尝试查询所有评审
        if not review_info or ("rid" not in review_info and "reviewId" not in review_info):
            path = f"/api/review?p={int(pid)}"
            all_reviews_data = self.http_get(path)
            if all_reviews_data and all_reviews_data.get("ok") and all_reviews_data.get("reviews"):
                for review in all_reviews_data["reviews"]:
                    if review.get("reviewer_email", "").lower() == reviewer_email.lower():
                        review_info = review
                        break

        if review_info:
            # API返回的字段名是 "rid"，但我们也支持 "reviewId" 作为备用
            review_id = review_info.get("rid") or review_info.get("reviewId")
            if not review_id:
                return {"ok": False, "data": {"message": f"评审信息中没有reviewId (rid) (pid={pid}, email={reviewer_email})"}}
            # 检查评审状态，如果已经是 acknowledged 或更高，就不需要接受了
            # 状态可能是字符串 "status" 或数字 "reviewStatus"
            status = review_info.get("status", "")
            status_num = review_info.get("reviewStatus", -1)
            
            # 如果状态是字符串，检查是否已经是 accepted 或更高
            if isinstance(status, str):
                accepted_statuses = ["acknowledged", "draft", "delivered", "approved", "complete"]
                if status in accepted_statuses:
                    return {"ok": True, "data": {"message": "评审已接受", "reviewId": review_id}}
            elif status_num >= 1:
                # 评审已经是被接受状态了
                return {"ok": True, "data": {"message": "评审已接受", "reviewId": review_id}}

            # 调用 acceptreview API 接受评审（POST 请求，需要表单编码）
            accept_path = f"/api/acceptreview?p={int(pid)}&r={review_id}"
            accept_res = self.http_post_form(accept_path, {})

            # 如果第一次失败，重试一次（等待更长时间）
            if not accept_res["ok"]:
                time.sleep(1.0)
                accept_res = self.http_post_form(accept_path, {})

            return accept_res
        return {"ok": False, "data": {"message": f"无法找到评审信息 (pid={pid}, email={reviewer_email})"}}

    def get_review_info(self, pid: int, reviewer_email: str) -> Optional[Dict]:
        """获取评审信息（使用 u 参数直接查询特定评审者）"""
        # 使用 review API 的 u 参数直接查询特定评审者的评审信息（GET 请求）
        path = f"/api/review?p={int(pid)}&u={urllib.parse.quote(reviewer_email)}"
        data = self.http_get(path)

        # 使用 u 参数查询会直接返回该评审者的评审列表
        if data and data.get("ok") and data.get("reviews") and len(data["reviews"]) > 0:
            # 返回第一个评审（通常只有一个）
            return data["reviews"][0]
        
        # 如果使用u参数失败，尝试查询所有评审并匹配邮箱
        if not data or not data.get("ok") or not data.get("reviews"):
            path_all = f"/api/review?p={int(pid)}"
            data_all = self.http_get(path_all)
            if data_all and data_all.get("ok") and data_all.get("reviews"):
                for review in data_all["reviews"]:
                    if review.get("reviewer_email", "").lower() == reviewer_email.lower():
                        return review
        
        return None

    def submit_review(self, pid: int, reviewer_email: str, fields: Dict, round_val: Optional[str] = None) -> Dict[str, Any]:
        """提交评审内容（PC身份代表 reviewer 提交到 pid）"""
        # 先确保评审已被接受（如果是 external 类型且状态为空）
        self.ensure_review_accepted(pid, reviewer_email)

        # 获取评审信息以确定reviewId，可能需要等待并重试
        review_info = None
        max_retries = 5
        for retry in range(max_retries):
            review_info = self.get_review_info(pid, reviewer_email)
            # API返回的字段名是 "rid" 而不是 "reviewId"
            if review_info and ("rid" in review_info or "reviewId" in review_info):
                break
            if retry < max_retries - 1:
                time.sleep(0.5)  # 等待0.5秒后重试
        
        if not review_info or ("rid" not in review_info and "reviewId" not in review_info):
            # get_review_info 已经尝试了备用方法，如果还是找不到，显示详细错误
            print(f"  ⚠️  无法找到评审信息，尝试直接查询所有评审...")
            path = f"/api/review?p={int(pid)}"
            all_reviews_data = self.http_get(path)
            if all_reviews_data and all_reviews_data.get("ok") and all_reviews_data.get("reviews"):
                print(f"  📋 找到 {len(all_reviews_data['reviews'])} 个评审:")
                for review in all_reviews_data["reviews"]:
                    reviewer_email_found = review.get("reviewer_email", "")
                    rid = review.get("rid") or review.get("reviewId")
                    print(f"    - rid={rid}, reviewer={reviewer_email_found}")
                    if reviewer_email_found.lower() == reviewer_email.lower():
                        review_info = review
                        print(f"  ✅ 匹配到评审: rid={rid}")
                        break
            
        if not review_info:
            return {"ok": False, "data": {"error": f"无法找到评审信息 (pid={pid}, reviewer={reviewer_email})"}}

        # API返回的字段名是 "rid"，但我们也支持 "reviewId" 作为备用
        review_id = review_info.get("rid") or review_info.get("reviewId")
        if not review_id:
            return {"ok": False, "data": {"error": f"评审信息中没有reviewId (rid) (pid={pid}, reviewer={reviewer_email})"}}

        # 重新获取最新的评审信息以确保版本号正确
        time.sleep(0.5)
        review_info = self.get_review_info(pid, reviewer_email)
        if not review_info:
            review_info = self.get_review_info(pid, reviewer_email)
        
        # 构建基础参数
        params = {
            "p": str(int(pid)),  # 明确包含 p 参数
            "r": str(review_id),  # 添加 reviewId 参数
            "reviewerEmail": reviewer_email.strip(),  # 使用 reviewerEmail 让PC代表审稿人提交
            "ready": "1",  # 设置为ready状态（提交评审）
            "override": "1",  # 允许PC覆盖截止日期限制
            "update": "1"  # 触发更新处理
        }
        
        # 添加版本控制参数（必须正确设置以避免并发冲突）
        # 从 review_info 中获取当前的 reviewTime 作为 if_vtag_match
        if "version" in review_info or "reviewTime" in review_info:
            review_time = review_info.get("version") or review_info.get("reviewTime")
            if review_time:
                params["if_vtag_match"] = str(review_time)
        
        # 获取当前的 edit_version（reviewEditVersion），如果没有则从 reviewTime 计算
        if "reviewEditVersion" in review_info and review_info["reviewEditVersion"]:
            current_edit_version = int(review_info["reviewEditVersion"])
            params["edit_version"] = str(current_edit_version + 1)
        elif "version" in review_info or "reviewTime" in review_info:
            # 如果没有 edit_version，使用一个默认值（通常新评审从0开始）
            params["edit_version"] = "1"
        
        # 添加字段（同时使用字段名和可能的 short_id 以确保兼容性）
        # 注意：对于文本字段，HotCRP 可能需要 has_ 前缀来标记字段存在
        for k, v in fields.items():
            if v is not None and v != "":
                # 确保值是字符串格式（除了某些特殊情况）
                if isinstance(v, bool):
                    params[k] = "1" if v else "0"
                elif isinstance(v, (int, float)):
                    params[k] = str(v)
                else:
                    params[k] = str(v)
                    # 对于文本字段，添加 has_ 前缀来标记字段存在（某些字段可能需要）
                    # 例如：has_t01=1 表示 t01 字段有值
                    if k.startswith("t") and k[1:].isdigit():
                        params[f"has_{k}"] = "1"

        # 添加可选参数
        if round_val and round_val.strip():
            params["round"] = round_val.strip()
        
        # 重要：确保 paperId 参数也包含在请求中
        params["paperId"] = str(int(pid))
        
        # 只执行一次提交（合并保存和提交步骤）
        # 使用 submitreview 和 update 参数直接提交
        params["submitreview"] = "1"
        
        # 使用 /api/review 端点提交（支持 Bearer token 认证）
        api_path = f"/api/review?p={int(pid)}&r={review_id}"
        
        print(f"  📤 正在提交评审...")
        print(f"  📋 提交的字段: {', '.join([f'{k}={str(v)[:30]}...' if len(str(v)) > 30 else f'{k}={v}' for k, v in fields.items()])}")
        print(f"  🔄 使用 /api/review 端点提交...")
        
        result = self.http_post_form_review(api_path, params)
        
        # 如果提交返回JSON但没有保存成功，尝试先保存草稿再提交
        if result.get("ok") and result.get("data", {}).get("message_list"):
            # 检查是否有错误消息
            message_list = result.get("data", {}).get("message_list", [])
            has_error = any(msg.get("status", 0) >= 2 for msg in message_list if isinstance(msg, dict))
            
            if has_error:
                # 等待一下让服务器处理
                time.sleep(1.5)
                
                # 再次检查评审是否已保存
                verify_info = self.get_review_info(pid, reviewer_email)
                if verify_info:
                    field_saved = (verify_info.get("PapSum") not in (None, "") or 
                                 verify_info.get("Str") not in (None, "") or
                                 verify_info.get("OveMer") is not None)
                    status = verify_info.get("reviewStatus", -1)
                    
                    # 如果状态仍然是 empty (0) 或 acknowledged (1)，且字段未保存，尝试保存草稿
                    if status <= 1 and not field_saved:
                        print(f"  📝 字段未保存，尝试先保存草稿...")
                        draft_params = params.copy()
                        draft_params.pop("submitreview", None)
                        draft_params["savedraft"] = "1"
                        draft_result = self.http_post_form_review(api_path, draft_params)
                        
                        if draft_result.get("ok"):
                            time.sleep(1.0)
                            submit_params = params.copy()
                            submit_params["submitreview"] = "1"
                            result = self.http_post_form_review(api_path, submit_params)
        
        return result

    def ensure_review_accepted(self, pid: int, reviewer_email: str):
        """确保评审已被接受（如果是空状态）"""
        review_info = self.get_review_info(pid, reviewer_email)

        if review_info and "reviewId" in review_info:
            review_id = review_info["reviewId"]
            # 检查评审状态，如果是空状态，尝试接受
            # reviewStatus: 0=empty, 1=acknowledged, 2=draft, 3=delivered, 4=approved, 5=complete
            if review_info.get("reviewStatus", -1) == 0:
                # 评审状态为空，需要接受
                accept_path = f"/api/acceptreview?p={int(pid)}&r={review_id}"
                self.http_post_form(accept_path, {})
                # 等待一下让状态更新
                time.sleep(0.3)

    def import_from_csv(self, csv_file: str) -> bool:
        """从CSV导入评审"""
        print("📝 CSV批量导入评审")
        print("=" * 40)
        print(f"📁 文件: {csv_file}")
        print(f"🔧 模式: {'干运行测试' if self.dry_run else '实际导入'}\n")

        import os
        if not os.path.exists(csv_file):
            raise Exception(f"CSV文件不存在: {csv_file}")

        count = 0
        success = 0
        row_num = 1

        with open(csv_file, 'r', encoding='utf-8') as f:
            reader = csv.reader(f)
            headers = next(reader, None)
            if not headers:
                raise Exception("CSV无表头内容")

            # 标准列名（与中文/特殊字符兼容：仅用于匹配，不改变原值）
            normalized = [h.lower().strip() for h in headers]

            for row in reader:
                row_num += 1
                if len(row) != len(headers):
                    print(f"⚠️  第{row_num}行: 列数不匹配，跳过")
                    continue

                data = {normalized[i]: row[i] for i in range(len(row))}

                # 取新表头（支持英文和中文列名）
                pid = data.get("paper id", "").strip()
                # 支持英文列名 "name" 和中文列名 "审稿人姓名"
                reviewer_name = data.get("name", data.get("审稿人姓名", "")).strip()
                # 支持英文列名 "email" 和中文列名 "审稿人邮箱"
                reviewer = data.get("email", data.get("审稿人邮箱", "")).strip()
                round_val = data.get("round", "").strip()

                if not pid or not reviewer:
                    print(f"⚠️  第{row_num}行: 缺少 paper id 或 审稿人邮箱，跳过")
                    continue

                # 字段映射：将CSV列映射到HotCRP评审字段键
                # 尝试同时使用字段名称和short_id以确保兼容性
                fields = {}

                # Paper summary (文本字段) - 尝试使用short_id t01 和名称
                if "papersummary" in data and data["papersummary"].strip():
                    val = data["papersummary"].strip()
                    fields["t01"] = val  # 只使用short_id，避免冲突

                # Strengths (文本字段，复数形式)
                if "strengths" in data and data["strengths"].strip():
                    val = data["strengths"].strip()
                    fields["Strengths"] = val

                # Weaknesses (文本字段，复数形式 - 注意是复数)
                if "weakness" in data and data["weakness"].strip():
                    val = data["weakness"].strip()
                    fields["Weaknesses"] = val
                # 也支持直接使用 "weaknesses" (复数)
                if "weaknesses" in data and data["weaknesses"].strip():
                    val = data["weaknesses"].strip()
                    fields["Weaknesses"] = val

                # Overall merit (评分字段，1-5) - 使用short_id s01
                if "overall merit（1-5）" in data and data["overall merit（1-5）"].strip():
                    val = data["overall merit（1-5）"].strip()
                    try:
                        int_val = int(val)
                        fields["s01"] = int_val  # 只使用short_id
                    except ValueError:
                        fields["s01"] = val
                        fields["Overall merit"] = val

                # Reviewer expertise (评分字段，1-4) - 使用short_id s02
                if "reviewer expertise（1-4）" in data and data["reviewer expertise（1-4）"].strip():
                    val = data["reviewer expertise（1-4）"].strip()
                    try:
                        int_val = int(val)
                        fields["s02"] = int_val  # 只使用short_id
                    except ValueError:
                        fields["s02"] = val
                        fields["Reviewer expertise"] = val

                # Comments for authors (文本字段) - 使用short_id t02
                if "comments for author" in data and data["comments for author"].strip():
                    val = data["comments for author"].strip()
                    fields["t02"] = val  # 只使用short_id
                # 也支持单数形式 "author"
                if "comments for authors" in data and data["comments for authors"].strip():
                    val = data["comments for authors"].strip()
                    fields["t02"] = val  # 只使用short_id

                # 移除空字段
                fields = {k: v for k, v in fields.items() if v is not None and v != ""}

                count += 1
                round_info = f" round={round_val}" if round_val else ""
                name_info = f" ({reviewer_name})" if reviewer_name else ""
                print(f"📄 [{count}] pid=#{pid} reviewer={reviewer}{name_info}{round_info}")

                try:
                    # 1) 确保分配
                    print(f"  🔄 正在分配评审...")
                    assign_res = self.ensure_assignment(int(pid), reviewer, reviewer_name, round_val if round_val else None)
                    if not assign_res["ok"]:
                        print(f"❌ 分配失败: {json.dumps(assign_res['data'], ensure_ascii=False)}\n")
                        continue
                    print(f"  ✅ 分配成功")

                    # 1.5) 再次确保评审已接受（在提交前再次检查）
                    print(f"  🔄 正在接受评审...")
                    final_accept_res = self.auto_accept_review(int(pid), reviewer)
                    if not final_accept_res["ok"] and not self.dry_run:
                        # 记录警告但继续尝试提交
                        print(f"⚠️  自动接受评审可能失败: {json.dumps(final_accept_res.get('data', {}), ensure_ascii=False)}")
                        print("  但继续尝试提交...")
                    else:
                        print(f"  ✅ 评审已接受")

                    # 2) 提交评审
                    submit_res = self.submit_review(int(pid), reviewer, fields, round_val if round_val else None)
                    if submit_res["ok"]:
                        # 等待更长时间确保评审已保存到数据库
                        time.sleep(2.0)

                        # 验证评审是否真正保存（检查评审状态和内容）
                        verify_info = None
                        # 重试几次获取评审信息，确保数据库已更新
                        for verify_retry in range(3):
                            verify_info = self.get_review_info(int(pid), reviewer)
                            if verify_info:
                                # 检查字段是否已保存
                                if verify_info.get("PapSum") not in (None, "") or verify_info.get("OveMer") is not None:
                                    break  # 字段已保存，可以退出重试循环
                            if verify_retry < 2:
                                time.sleep(1.0)  # 等待1秒后重试
                        
                        if verify_info:
                            # 检查状态字段（可能是 "status" 字符串或 "reviewStatus" 数字）
                            status = verify_info.get("status")
                            if isinstance(status, str):
                                status_text = status
                            else:
                                status = verify_info.get("reviewStatus", -1)
                                status_texts = ["empty", "acknowledged", "draft", "delivered", "approved", "complete"]
                                status_text = status_texts[status] if 0 <= status < len(status_texts) else "unknown"
                            print(f"  📊 评审状态: {status_text}")

                            # 检查字段是否被保存
                            field_saved = False
                            if verify_info.get("PapSum") not in (None, ""):
                                field_saved = True
                                print("  ✅ Paper summary 已保存")
                            if verify_info.get("Str") not in (None, ""):
                                field_saved = True
                                print("  ✅ Strengths 已保存")
                            if verify_info.get("Wea") not in (None, ""):
                                field_saved = True
                                print("  ✅ Weaknesses 已保存")
                            if verify_info.get("OveMer") is not None:
                                field_saved = True
                                print(f"  ✅ Overall merit 已保存: {verify_info['OveMer']}")
                            if verify_info.get("ComAut") not in (None, ""):
                                field_saved = True
                                print("  ✅ Comments for authors 已保存")

                            # 检查状态（修复类型错误：status可能是字符串）
                            status_is_low = False
                            if isinstance(status_text, str):
                                # 如果status_text是字符串，检查是否为空状态
                                status_is_low = status_text in ["empty", "acknowledged"]
                            else:
                                # 如果status是数字
                                status_num = verify_info.get("reviewStatus", -1)
                                if isinstance(status_num, int):
                                    status_is_low = status_num <= 1
                            
                            # 如果状态仍然是empty或acknowledged，且字段未保存，可能是保存失败
                            if status_is_low and not self.dry_run and not field_saved:
                                print(f"  ⚠️  警告: 评审状态仍为 {status_text}，且字段未保存，可能保存失败")

                        # 只有在字段真正保存后才认为成功
                        if verify_info:
                            status = verify_info.get("status", "")
                            if isinstance(status, str):
                                status_text = status
                            else:
                                status_num = verify_info.get("reviewStatus", -1)
                                status_texts = ["empty", "acknowledged", "draft", "delivered", "approved", "complete"]
                                status_text = status_texts[status_num] if isinstance(status_num, int) and 0 <= status_num < len(status_texts) else "unknown"
                            
                            field_saved = (verify_info.get("PapSum") not in (None, "") or 
                                         verify_info.get("Str") not in (None, "") or
                                         verify_info.get("OveMer") is not None)
                            
                            # 如果状态不是empty且字段已保存，才认为真正成功
                            if status_text not in ["empty", "acknowledged"] or field_saved:
                                if self.dry_run:
                                    print("✅ 干运行通过\n")
                                else:
                                    print("🎉 提交成功\n")
                                success += 1
                            else:
                                print("⚠️  提交可能失败：评审状态仍为空或字段未保存\n")
                        else:
                            print("⚠️  无法验证评审是否保存\n")
                    else:
                        print(f"❌ 提交失败: {json.dumps(submit_res['data'], ensure_ascii=False, indent=2)}\n")
                except Exception as e:
                    print(f"❌ 异常: {str(e)}\n")

        print("=" * 40)
        print(f"📊 成功: {success} / {count}")

        return success > 0


def create_review_example_csv() -> str:
    """生成示例CSV"""
    csv_content = "paper id,name,email,papersummary,strengths,weakness,overall merit（1-5）,reviewer expertise（1-4）,comments for author\n"
    csv_content += "316,张三,reviewer1@example.edu,论文总结A,优势A,不足A,4,3,给作者的一段建议A\n"
    csv_content += "316,李四,external@example.com,论文总结B,优势B,不足B,2,2,给作者的一段建议B\n"

    filename = "reviews_example.csv"
    with open(filename, 'w', encoding='utf-8') as f:
        f.write(csv_content)

    print(f"✅ 创建示例CSV: {filename}\n")
    print("📋 CSV内容:")
    print(csv_content)
    return filename


def main():
    """主程序"""
    print("CSV 批量导入评审工具")
    print("=" * 30)
    print()
    print("请选择操作:")
    print("1. 创建示例CSV")
    print("2. 干运行测试导入")
    print("3. 实际批量导入")
    print("请输入选择 (1-3): ", end='')

    choice = input().strip()

    if choice == "1":
        create_review_example_csv()
    elif choice == "2":
        csv_file = "reviews_example.csv"
        import os
        if not os.path.exists(csv_file):
            print("示例文件不存在，先创建...")
            csv_file = create_review_example_csv()
        importer = ReviewCSVImporter(dry_run=True)
        importer.import_from_csv(csv_file)
    elif choice == "3":
        csv_file = "reviews_example.csv"
        import os
        if not os.path.exists(csv_file):
            print("示例文件不存在，先创建...")
            csv_file = create_review_example_csv()
        else:
            # 验证文件内容
            with open(csv_file, 'r', encoding='utf-8') as f:
                first_line = f.readline().strip()
                second_line = f.readline().strip()
                print(f"📋 读取CSV文件: {csv_file}")
                print(f"   表头: {first_line}")
                if second_line:
                    print(f"   第一行: {second_line[:80]}...")

        print("\n⚠️  确认要执行实际导入吗？(y/N): ", end='')
        confirm = input().strip().lower()
        if confirm == "y":
            importer = ReviewCSVImporter(dry_run=False)
            importer.import_from_csv(csv_file)
    else:
        print("无效选择")


if __name__ == "__main__":
    main()

