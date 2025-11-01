#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
修复版CSV批量导入 - 支持国家信息和邮箱 (Python版本)

功能特性：
- 支持在作者信息中包含邮箱（格式：姓名 <email@example.com> (单位, 国家)）
- 如果CSV中没有提供邮箱，会自动生成（基于姓名拼音）
- 支持多种作者格式：带国家信息、带国家代码、自动推断国家
- 使用分号(;)分隔多个作者，避免逗号冲突
"""

import csv
import re
import json
import base64
import os
import sys
import requests
from pathlib import Path
from typing import List, Dict, Optional, Tuple


class CountryAwareImporter:
    """支持国家信息和邮箱的CSV批量导入器"""
    
    def __init__(self, dry_run: bool = True):
        self.api_token = "hct_JSbozdULAJCcNBChfuwSDhWaFRuRYKUtbscBGEuBaMxD"
        self.base_url = "http://hotcrp.treasurew.com"
        self.dry_run = dry_run
    
    def parse_authors_with_country(self, authors_str: str) -> List[Dict]:
        """智能解析作者信息（支持国家）"""
        if not authors_str or not authors_str.strip():
            return [{
                "name": "Unknown Author",
                "email": "unknown@example.edu",
                "affiliation": "Unknown Institution",
                "country": "CN"
            }]
        
        # 使用分号分隔作者，避免与国家信息中的逗号冲突
        author_parts = authors_str.split(";")
        
        # 如果没有分号，尝试智能分割
        if len(author_parts) == 1:
            author_parts = self.smart_split_authors(authors_str)
        
        authors = []
        for i, author_part in enumerate(author_parts):
            author_part = author_part.strip()
            if not author_part:
                continue
            
            author_info = self.parse_individual_author(author_part)
            # 如果CSV中没有提供邮箱，则自动生成
            if not author_info.get("email"):
                author_info["email"] = self.generate_valid_email(author_info["name"], i + 1)
            
            authors.append(author_info)
        
        return authors if authors else [{
            "name": "Unknown Author",
            "email": "unknown@example.edu",
            "affiliation": "Unknown Institution",
            "country": "CN"
        }]
    
    def smart_split_authors(self, authors_str: str) -> List[str]:
        """智能分割作者（处理逗号分隔的情况）"""
        parts = []
        current = ""
        paren_count = 0
        bracket_count = 0
        
        for char in authors_str:
            if char == "(":
                paren_count += 1
            elif char == ")":
                paren_count -= 1
            elif char == "[":
                bracket_count += 1
            elif char == "]":
                bracket_count -= 1
            elif char == "," and paren_count == 0 and bracket_count == 0:
                parts.append(current.strip())
                current = ""
                continue
            
            current += char
        
        if current.strip():
            parts.append(current.strip())
        
        return parts
    
    def parse_individual_author(self, author_str: str) -> Dict:
        """解析单个作者信息（支持邮箱）"""
        name = ""
        affiliation = "Unknown Institution"
        country = "CN"
        email = None
        
        # 首先尝试提取邮箱（支持 <email@example.com> 格式）
        email_pattern = r"<([^>]+@[^>]+)>"
        email_match = re.search(email_pattern, author_str)
        if email_match:
            email = email_match.group(1).strip()
            # 从原字符串中移除邮箱部分，便于后续解析
            author_str = re.sub(email_pattern, "", author_str).strip()
        
        # 格式1: "姓名 <email> (单位, 国家)" 或 "姓名 (单位, 国家)"
        match = re.match(r"^(.+?)\s*\((.+?),\s*(.+?)\)$", author_str)
        if match:
            name = match.group(1).strip()
            affiliation = match.group(2).strip()
            country = self.parse_country(match.group(3).strip())
        # 格式2: "姓名 <email> (单位) [国家代码]" 或 "姓名 (单位) [国家代码]"
        elif re.match(r"^(.+?)\s*\((.+?)\)\s*\[(.+?)\]$", author_str):
            match = re.match(r"^(.+?)\s*\((.+?)\)\s*\[(.+?)\]$", author_str)
            name = match.group(1).strip()
            affiliation = match.group(2).strip()
            country = self.parse_country(match.group(3).strip())
        # 格式3: "姓名 <email> (单位)" 或 "姓名 (单位)"
        elif re.match(r"^(.+?)\s*\((.+?)\)$", author_str):
            match = re.match(r"^(.+?)\s*\((.+?)\)$", author_str)
            name = match.group(1).strip()
            affiliation = match.group(2).strip()
            country = self.infer_country_from_affiliation(affiliation)
        # 格式4: "姓名 <email>" 或 "姓名"
        else:
            name = author_str.strip()
            affiliation = "Unknown Institution"
            country = "CN"
        
        return {
            "name": name,
            "affiliation": affiliation,
            "country": country,
            "email": email
        }
    
    def parse_country(self, country_input: str) -> str:
        """解析国家信息"""
        country_map = {
            # 中文
            "中国": "CN", "美国": "US", "英国": "GB", "日本": "JP",
            "德国": "DE", "法国": "FR", "加拿大": "CA", "澳大利亚": "AU",
            "新加坡": "SG", "韩国": "KR", "意大利": "IT", "荷兰": "NL",
            "瑞士": "CH",
            
            # 英文
            "china": "CN", "usa": "US", "united states": "US", "america": "US",
            "uk": "GB", "britain": "GB", "united kingdom": "GB",
            "japan": "JP", "germany": "DE", "france": "FR",
            "canada": "CA", "australia": "AU", "singapore": "SG", "korea": "KR",
            "switzerland": "CH",
            
            # ISO代码（直接返回）
            "cn": "CN", "us": "US", "gb": "GB", "jp": "JP", "de": "DE",
            "fr": "FR", "ca": "CA", "au": "AU", "sg": "SG", "kr": "KR",
            "ch": "CH", "it": "IT", "nl": "NL"
        }
        
        key = country_input.strip().lower()
        return country_map.get(key, "CN")
    
    def infer_country_from_affiliation(self, affiliation: str) -> str:
        """从单位推断国家"""
        aff_lower = affiliation.lower()
        
        # 中国
        if re.search(r"(清华|北大|北京大学|tsinghua|peking|beijing|fudan|复旦|交大|sjtu|浙大|zju|中科院|cas|华为|腾讯|阿里)", aff_lower):
            return "CN"
        
        # 美国
        if re.search(r"(mit|stanford|harvard|berkeley|cmu|ucla|caltech|princeton|yale|columbia|google|microsoft|apple|facebook)", aff_lower):
            return "US"
        
        # 英国
        if re.search(r"(oxford|cambridge|imperial|ucl|london|edinburgh|manchester)", aff_lower):
            return "GB"
        
        # 日本
        if re.search(r"(tokyo|kyoto|osaka|waseda|keio|sony|toyota|nintendo)", aff_lower):
            return "JP"
        
        # 瑞士
        if re.search(r"(eth|zurich|epfl|lausanne|bern|basel|switzerland|swiss)", aff_lower):
            return "CH"
        
        return "CN"  # 默认
    
    def generate_valid_email(self, name: str, index: int) -> str:
        """生成有效邮箱"""
        pinyin_map = {
            "张": "zhang", "李": "li", "王": "wang", "刘": "liu",
            "陈": "chen", "杨": "yang", "赵": "zhao", "黄": "huang",
            "周": "zhou", "吴": "wu", "徐": "xu", "孙": "sun",
            "伟": "wei", "娜": "na", "强": "qiang", "芳": "fang",
            "明": "ming", "华": "hua", "军": "jun", "平": "ping"
        }
        
        email_parts = []
        
        for char in name:
            if char in pinyin_map:
                email_parts.append(pinyin_map[char])
            elif re.match(r"^[a-zA-Z]$", char):
                email_parts.append(char.lower())
        
        if not email_parts:
            email_parts = [f"author{index}"]
        
        return "".join(email_parts) + str(index) + "@example.edu"
    
    def convert_to_api(self, csv_data: Dict, row_num: int) -> Optional[Dict]:
        """转换为API格式"""
        paper = {
            "object": "paper",
            "pid": "new",
            "title": csv_data.get("title", "").strip(),
            "authors": self.parse_authors_with_country(csv_data.get("authors", "")),
            "abstract": csv_data.get("abstract", "").strip(),
            "track": csv_data.get("track", "test").strip(),
            "status": "submitted"
        }
        
        # 处理PDF
        if csv_data.get("pdf"):
            pdf_info = self.process_pdf(csv_data["pdf"], row_num)
            if pdf_info:
                paper["submission"] = pdf_info
        
        return paper
    
    def process_pdf(self, pdf_path: str, row_num: int) -> Optional[Dict]:
        """处理PDF"""
        search_paths = [
            "/srv/www/api/HotCRP_CSV_Import_Solution/" + pdf_path,
            "/srv/www/api/" + pdf_path,
            pdf_path,
            os.path.join(os.path.dirname(__file__), pdf_path),
            os.path.join(os.getcwd(), pdf_path)
        ]
        
        for path in search_paths:
            if os.path.exists(path):
                try:
                    with open(path, "rb") as f:
                        content = f.read()
                    return {
                        "content_base64": base64.b64encode(content).decode("utf-8"),
                        "type": "application/pdf",
                        "filename": os.path.basename(pdf_path)
                    }
                except Exception as e:
                    print(f"⚠️  第{row_num}行: PDF文件读取失败 {path}: {e}")
                    continue
        
        print(f"⚠️  第{row_num}行: PDF文件未找到: {pdf_path}")
        return None
    
    def import_paper(self, paper: Dict) -> Dict:
        """导入论文"""
        url = f"{self.base_url}/api/paper"
        if self.dry_run:
            url += "?dry_run=1"
        
        headers = {
            "Authorization": f"bearer {self.api_token}",
            "Content-Type": "application/json"
        }
        
        try:
            response = requests.post(
                url,
                json=paper,
                headers=headers,
                timeout=60
            )
            response.raise_for_status()
            data = response.json()
            return {
                "success": data.get("ok", False),
                "data": data
            }
        except requests.exceptions.RequestException as e:
            raise Exception(f"API请求失败: {e}")
    
    def import_from_csv(self, csv_file: str) -> bool:
        """从CSV导入"""
        print("🌍 支持国家信息的CSV批量导入")
        print("=" * 45)
        print(f"📁 文件: {csv_file}")
        print(f"🔧 模式: {'干运行测试' if self.dry_run else '实际导入'}\n")
        
        papers = []
        row_num = 1
        
        try:
            with open(csv_file, "r", encoding="utf-8") as f:
                reader = csv.DictReader(f)
                for row in reader:
                    row_num += 1
                    if not row.get("title") or not row["title"].strip():
                        continue
                    
                    paper = self.convert_to_api(row, row_num)
                    if paper:
                        print(f"👥 第{row_num}行作者解析:")
                        for i, author in enumerate(paper["authors"], 1):
                            print(f"   {i}. {author['name']} [{author['country']}] - {author['affiliation']} - 📧 {author['email']}")
                        print()
                        papers.append(paper)
        except FileNotFoundError:
            print(f"❌ 错误: CSV文件不存在: {csv_file}")
            return False
        except Exception as e:
            print(f"❌ 错误: 读取CSV文件失败: {e}")
            return False
        
        print(f"📊 准备导入 {len(papers)} 篇论文\n")
        
        success = 0
        for index, paper in enumerate(papers, 1):
            title_preview = paper["title"][:40] + "..." if len(paper["title"]) > 40 else paper["title"]
            print(f"📄 [{index}] {title_preview}")
            
            try:
                result = self.import_paper(paper)
                if result["success"]:
                    if self.dry_run:
                        print(f"✅ [{index}] 干运行通过")
                    else:
                        paper_id = result["data"].get("paper", {}).get("pid", "unknown")
                        print(f"🎉 [{index}] 导入成功! Paper ID: #{paper_id}")
                    success += 1
                else:
                    print(f"❌ [{index}] 导入失败")
                    if "message_list" in result.get("data", {}):
                        for msg in result["data"]["message_list"]:
                            field = msg.get("field", "")
                            message = msg.get("message", "")
                            print(f"  ⚠️  {field}: {message}")
            except Exception as e:
                print(f"❌ [{index}] 异常: {e}")
            
            print()
        
        print("=" * 45)
        print(f"📊 成功: {success} / {len(papers)}")
        
        return success > 0


def create_country_aware_example(output_dir: Optional[str] = None) -> str:
    """创建正确格式的示例CSV"""
    if output_dir is None:
        output_dir = os.getcwd()
    
    csv_content = "title,authors,abstract,pdf,track,topics\n"
    csv_content += "\"深度学习研究\",\"张伟 <zhangwei@tsinghua.edu.cn> (清华大学, 中国); John Smith <john@mit.edu> (MIT, 美国)\",\"深度学习研究论文\",\"test.pdf\",\"test\",\"AI\"\n"
    csv_content += "\"区块链应用\",\"王强 (清华大学); Mary Johnson <mary@stanford.edu> (Stanford) [US]\",\"区块链应用研究\",\"test.pdf\",\"test\",\"区块链\"\n"
    csv_content += "\"自然语言处理\",\"刘芳 (复旦大学, CN); David Brown (Oxford) [GB]\",\"NLP技术进展\",\"test.pdf\",\"test\",\"NLP\"\n"
    
    filename = os.path.join(output_dir, "example_batch.csv")
    
    with open(filename, "w", encoding="utf-8") as f:
        f.write(csv_content)
    
    print(f"✅ 创建支持国家信息和邮箱的示例CSV: {filename}\n")
    print("📋 正确格式（使用分号分隔作者）:")
    print(csv_content)
    print("🔑 格式说明:")
    print("  - 作者间用分号(;)分隔")
    print("  - 邮箱格式（可选）: 姓名 <email@example.com> (单位, 国家)")
    print("  - 国家信息格式: 姓名 (单位, 国家)")
    print("  - 国家代码格式: 姓名 (单位) [国家代码]")
    print("  - 自动推断: 姓名 (单位) - 如果没有提供邮箱，会自动生成")
    print("  - 邮箱位置灵活: 可以在姓名后，也可以在单位/国家信息后\n")
    
    return filename


def main():
    """主程序"""
    if len(sys.argv) > 1 and sys.argv[1] in ["--help", "-h"]:
        print("CSV批量导入工具 - 支持国家信息和邮箱 (Python版本)")
        print("=" * 50)
        print("\n用法:")
        print("  python csv_country_fixed.py [选项] [CSV文件路径]\n")
        print("选项:")
        print("  --dry-run, -d     干运行测试（不实际导入）")
        print("  --import, -i      实际导入（需要确认）")
        print("  --help, -h        显示帮助信息\n")
        print("示例:")
        print("  python csv_country_fixed.py --dry-run papers.csv")
        print("  python csv_country_fixed.py -i papers.csv")
        print("  python csv_country_fixed.py  # 交互式模式\n")
        return
    
    # 命令行参数模式
    csv_file = None
    dry_run = None
    
    if len(sys.argv) > 1:
        if sys.argv[1] in ["--dry-run", "-d"]:
            dry_run = True
            csv_file = sys.argv[2] if len(sys.argv) > 2 else None
        elif sys.argv[1] in ["--import", "-i"]:
            dry_run = False
            csv_file = sys.argv[2] if len(sys.argv) > 2 else None
        else:
            csv_file = sys.argv[1]
    
    # 如果指定了CSV文件，直接运行
    if csv_file:
        if not os.path.exists(csv_file):
            print(f"❌ 错误: CSV文件不存在: {csv_file}")
            return
        
        importer = CountryAwareImporter(dry_run if dry_run is not None else True)
        if dry_run is False:
            confirm = input("\n⚠️  确认要执行实际导入吗？(y/N): ").strip().lower()
            if confirm != "y":
                print("已取消")
                return
        
        importer.import_from_csv(csv_file)
        return
    
    # 交互式模式
    print("支持国家信息的CSV批量导入工具")
    print("=" * 40)
    print()
    
    print("请选择操作:")
    print("1. 创建正确格式的示例CSV")
    print("2. 干运行测试导入")
    print("3. 实际批量导入")
    choice = input("请输入选择 (1-3): ").strip()
    
    default_csv = "/srv/www/api/example_batch.csv"
    possible_paths = [
        "example_batch.csv",
        os.path.join(os.path.dirname(__file__), "example_batch.csv"),
        os.path.join(os.getcwd(), "example_batch.csv"),
        default_csv
    ]
    
    csv_file = None
    for path in possible_paths:
        if os.path.exists(path):
            csv_file = path
            break
    
    if choice == "1":
        output_dir = os.getcwd()
        print(f"CSV文件将保存到: {output_dir}")
        custom_dir = input("可以指定其他目录吗？(直接回车使用当前目录): ").strip()
        if custom_dir:
            output_dir = custom_dir
        create_country_aware_example(output_dir)
    
    elif choice == "2":
        if not csv_file:
            csv_file = default_csv
        if not os.path.exists(csv_file):
            print("示例文件不存在，先创建...")
            csv_file = create_country_aware_example()
        
        importer = CountryAwareImporter(True)
        importer.import_from_csv(csv_file)
    
    elif choice == "3":
        if not csv_file:
            csv_file = default_csv
        if not os.path.exists(csv_file):
            print("示例文件不存在，先创建...")
            csv_file = create_country_aware_example()
        
        confirm = input("\n⚠️  确认要执行实际导入吗？(y/N): ").strip().lower()
        if confirm == "y":
            importer = CountryAwareImporter(False)
            importer.import_from_csv(csv_file)
    
    else:
        print("无效选择")


if __name__ == "__main__":
    main()

