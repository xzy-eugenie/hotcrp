<?php
// settings.php -- HotCRP settings script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Settings_Batch::make_args($argv)->run());
}

class Settings_Batch {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var SettingValues */
    public $sv;
    /** @var ?string */
    public $filename;
    /** @var ?string */
    public $expr;
    /** @var string */
    public $text;
    /** @var bool */
    public $dry_run;
    /** @var bool */
    public $diff;

    function __construct(Contact $user, $arg) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->sv = new SettingValues($user);
        if (!empty($arg["_"])) {
            $this->filename = $arg["_"][0];
        }
        $this->expr = $arg["expr"] ?? null;
        if ($this->filename !== null && $this->expr !== null) {
            throw new CommandLineException("Give at most one of `--expr` and FILE");
        }
        $this->dry_run = isset($arg["dry-run"]);
        $this->diff = isset($arg["diff"]);
    }

    /** @param bool $new
     * @return string */
    static function output(SettingValues $sv, $new) {
        return json_encode($sv->all_json_choosev($new), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /** @return int */
    function run() {
        if ($this->filename === null && $this->expr === null) {
            fwrite(STDOUT, self::output($this->sv, false));
            return 0;
        }

        if ($this->diff) {
            $old_jsonstr = self::output(new SettingValues($this->user), false);
        } else {
            $old_jsonstr = null;
        }

        if ($this->expr !== null) {
            $s = $this->expr;
            $fn = "<expr>";
        } else if ($this->filename === "-") {
            $s = stream_get_contents(STDIN);
            $fn = "<stdin>";
        } else {
            $s = file_get_contents_throw($this->filename);
            $fn = $this->filename;
        }
        if ($s === "" || $s === false) {
            throw new CommandLineException("{$fn}: Empty file");
        }
        $this->sv->add_json_string($s, $fn);

        $this->sv->parse();
        if (!$this->dry_run && !$this->diff) {
            $this->sv->execute();
        }
        $fb = $this->sv->full_feedback_text();
        if ($fb === "" && !$this->diff) {
            if (empty($this->sv->updated_fields())) {
                $fb = "No changes\n";
            } else if ($this->dry_run) {
                $fb = "No errors\n";
            } else {
                $fb = "Settings saved\n";
            }
        }
        fwrite(STDERR, $fb);
        if ($this->diff) {
            $dmp = new dmp\diff_match_patch;
            $dmp->Line_Histogram = true;
            $old_jsonstr2 = self::output($this->sv, false);
            if ($old_jsonstr !== $old_jsonstr2) {
                $diff = $dmp->line_diff($old_jsonstr, $old_jsonstr2);
                fwrite(STDOUT, $dmp->line_diff_toUnified($diff));
                assert($old_jsonstr === $old_jsonstr2);
            }
            $new_jsonstr = self::output($this->sv, true);
            $diff = $dmp->line_diff($old_jsonstr, $new_jsonstr);
            fwrite(STDOUT, $dmp->line_diff_toUnified($diff));
        }
        return $this->sv->has_error() ? 1 : 0;
    }

    /** @return Settings_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "dry-run,d Do not modify settings.",
            "diff Output unified settings diff.",
            "expr:,e: =JSON Apply settings changes from JSON."
        )->description("Query or modify HotCRP settings in JSON format.
Usage: php batch/settings.php [FILE]")
         ->maxarg(1)
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new Settings_Batch($conf->root_user(), $arg);
    }
}
