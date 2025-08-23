<?php
// submission_track_stats.php -- Export per-track submission stats with totals as CSV
// Usage: php batch/submission_track_stats.php [--output=FILE]

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(SubmissionTrackStats_Batch::run_args($argv));
}

class SubmissionTrackStats_Batch {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var ?string */
    public $output_filename;
    /** @var CsvGenerator */
    public $csv;

    /** @param Conf $conf */
    function __construct($conf) {
        $this->conf = $conf;
        $this->user = $conf->root_user();
        $this->csv = new CsvGenerator;
    }

    /** @param array<string,mixed> $arg */
    function parse_arg($arg) {
        $this->output_filename = $arg["output"] ?? null;
    }

    /** Build stats rows and emit CSV */
    function run_and_output() {
        // Build mapping: track tag -> full display name
        $track_display_map = $this->build_track_display_map();
        // Prepare tracks list; include an explicit "default" bucket for papers without a track tag
        $track_tags = $this->conf->track_tags(); // list<string>
        $track_buckets = [];
        foreach ($track_tags as $tt) {
            $track_buckets[$tt] = [
                "track" => $tt,
                "total_papers" => 0,
                "registered" => 0,
                "submitted" => 0
            ];
        }
        $default_bucket_key = "default";
        $track_buckets[$default_bucket_key] = [
            "track" => $default_bucket_key,
            "total_papers" => 0,
            "registered" => 0,
            "submitted" => 0
        ];

        // Search all non-withdrawn papers
        $ps = new PaperSearch($this->user, ["q" => "all", "t" => "all"]);
        $pset = $this->conf->paper_set(["paperId" => $ps->paper_ids()]);

        foreach ($ps->sorted_paper_ids() as $pid) {
            $prow = $pset->get($pid);
            if (!$prow) {
                continue;
            }
            // Skip withdrawn
            if ($prow->timeWithdrawn > 0) {
                continue;
            }

            // Determine track bucket: first matching configured track tag; else default
            $bucket_key = $default_bucket_key;
            foreach ($track_tags as $tt) {
                if ($prow->has_tag($tt)) {
                    $bucket_key = $tt;
                    break;
                }
            }

            // Update counts
            $track_buckets[$bucket_key]["total_papers"] += 1;
            if ($prow->timeSubmitted > 0) {
                $track_buckets[$bucket_key]["submitted"] += 1;
            } else {
                $track_buckets[$bucket_key]["registered"] += 1; // registered but not submitted
            }
        }

        // Calculate totals for summary row
        $total_papers = 0;
        $total_registered = 0; 
        $total_submitted = 0;
        foreach ($track_buckets as $bucket) {
            $total_papers += $bucket["total_papers"];
            $total_registered += $bucket["registered"];
            $total_submitted += $bucket["submitted"];
        }

        // Prepare CSV header and rows
        $header = ["track", "total_papers", "registered", "submitted"];
        $this->csv->set_keys($header);
        $this->csv->set_header($header);

        foreach ($track_buckets as $bucket) {
            // Output per-track rows
            $track_key = $bucket["track"];
            $display_name = $track_display_map[strtolower($track_key)] ?? $track_key;
            $this->csv->add_row([
                "track" => $display_name,
                "total_papers" => $bucket["total_papers"],
                "registered" => $bucket["registered"],
                "submitted" => $bucket["submitted"]
            ]);
        }

        // Add a summary row with totals
        $this->csv->add_row([
            "track" => "ALL",
            "total_papers" => $total_papers,
            "registered" => $total_registered,
            "submitted" => $total_submitted
        ]);

        // Output
        if ($this->output_filename) {
            file_put_contents($this->output_filename, $this->csv->unparse());
        } else {
            echo $this->csv->unparse();
        }
    }

    /** @return int */
    static function run_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "output:,o: =FILE Output CSV file (default: stdout)"
        )->description("Output CSV with per-track paper counts (total/registered/submitted) and a final summary row with totals.\nUsage: php batch/submission_track_stats.php [--output=FILE]")
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        $b = new SubmissionTrackStats_Batch($conf);
        $b->parse_arg($arg);
        $b->run_and_output();
        return 0;
    }

    /** @return array<string,string> */
    private function build_track_display_map() {
        $map = [];

        // 1) 从数据库设置中读取自动打标签配置（UI 会保存在 tag_autosearch）
        $tas = $this->conf->setting_json("tag_autosearch");
        if ($tas && (is_object($tas) || is_array($tas))) {
            foreach ($tas as $tag => $spec) {
                $q = is_object($spec) ? ($spec->q ?? null) : (is_array($spec) ? ($spec["q"] ?? null) : null);
                if (is_string($tag) && is_string($q)
                    && preg_match('/Track:\s*"([^"]+)"/u', $q, $m)) {
                    $map[strtolower($tag)] = $m[1];
                }
            }
        }

        // 2) 若未取到，尝试从 advanced.json 读取（部署时常用作初始化配置）
        if (empty($map)) {
            $adv_path = dirname(__DIR__) . "/advanced.json"; // app/advanced.json
            if (is_readable($adv_path)) {
                $s = @file_get_contents($adv_path);
                if ($s !== false) {
                    $j = json_decode($s);
                    $ats = $j->automatic_tag ?? null;
                    if (is_array($ats)) {
                        foreach ($ats as $o) {
                            if (is_object($o)) {
                                $tag = $o->tag ?? null;
                                $q = $o->search ?? ($o->q ?? null);
                                if (is_string($tag) && is_string($q)
                                    && preg_match('/Track:\s*"([^"]+)"/u', $q, $m)) {
                                    $map[strtolower($tag)] = $m[1];
                                }
                            }
                        }
                    }
                }
            }
        }

        // 3) 最后兜底：使用代码内置映射（与分配页一致）
        if (empty($map)) {
            $map = [
                'cloud-edge' => 'Cloud & Edge Computing',
                'wsmc' => 'Wireless Sensing & Mobile Computing',
                'ii-internet' => 'Industrial Informatics & Internet',
                'infosec' => 'Information Security',
                'sads' => 'System and Applied Data Science',
                'big-data-fm' => 'Big Data & Foundation Models',
                'aigc-mapc' => 'AIGC & Multi-Agent Parallel Computing',
                'dist-storage' => 'Distributed Storage',
                'ngm' => 'Next-Generation Mobile Networks and Connected Systems',
                'rfa' => 'RF Computing and AIoT Application',
                'dsui' => 'Distributed System and Ubiquitous Intelligence',
                'wma' => 'Wireless and Mobile AIoT',
                'bdmls' => 'Big Data and Machine Learning Systems',
                'ncea' => 'SS:Networked Computing for Embodied AI',
                'aimc' => 'Artificial Intelligence for Mobile Computing',
                'idpm' => 'Intelligent Data Processing & Management',
                'badv' => 'Blockchain & Activation of Data Value',
                'mwt' => 'SS:Millimeter-Wave and Terahertz Sensing and Networks',
                'idsia' => 'Interdisciplinary Distributed System and IoT Applications',
                'spmus' => 'Security and Privacy in Mobile and Ubiquitous Systems',
            ];
        }

        return $map;
    }
}


