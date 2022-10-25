<?php
// pages/p_settings.php -- HotCRP chair-only conference settings management page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Settings_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var SettingValues */
    public $sv;

    /** @param SettingValues $sv
     * @param Contact $user */
    function __construct($sv, $user) {
        assert($sv->conf === $user->conf);
        $this->conf = $user->conf;
        $this->user = $user;
        $this->sv = $sv;
    }

    /** @param Qrequest $qreq
     * @return string */
    function choose_setting_group($qreq) {
        $req_group = $qreq->group;
        if (!$req_group && preg_match('/\A\/\w+\/*\z/', $qreq->path())) {
            $req_group = $qreq->path_component(0);
        }
        $want_group = $req_group;
        if (!$want_group && isset($_SESSION["sg"])) { // NB not conf-specific session, global
            $want_group = $_SESSION["sg"];
        }
        $want_group = $this->sv->canonical_group($want_group);
        if (!$want_group || !$this->sv->group_title($want_group)) {
            if ($this->conf->time_some_author_view_review()) {
                $want_group = $this->sv->canonical_group("decisions");
            } else if ($this->conf->time_after_setting("sub_sub")
                       || $this->conf->time_review_open()) {
                $want_group = $this->sv->canonical_group("reviews");
            } else {
                $want_group = $this->sv->canonical_group("submissions");
            }
        }
        if (!$want_group) {
            $this->user->escape();
        }
        if ($want_group !== $req_group && !$qreq->post && $qreq->post_empty()) {
            $this->conf->redirect_self($qreq, [
                "group" => $want_group, "#" => $this->sv->group_hashid($req_group)
            ]);
        }
        $this->sv->set_canonical_page($want_group);
        return $want_group;
    }

    /** @param Qrequest $qreq */
    function handle_update($qreq) {
        if ($this->sv->execute()) {
            $this->user->save_session("settings_highlight", $this->sv->message_field_map());
            if (!empty($this->sv->updated_fields())) {
                $this->conf->success_msg("<0>Changes saved");
            } else {
                $this->conf->feedback_msg(new MessageItem(null, "<0>No changes", MessageSet::MARKED_NOTE));
            }
            $this->sv->report();
            $this->conf->redirect_self($qreq);
        }
    }

    /** @param string $group
     * @param Qrequest $qreq */
    function print($group, $qreq) {
        $sv = $this->sv;
        $conf = $this->conf;

        $conf->header("Settings", "settings", [
            "subtitle" => $sv->group_title($group),
            "title_div" => '<hr class="c">',
            "body_class" => "leftmenu",
            "save_messages" => true
        ]);
        Icons::stash_defs("movearrow0", "movearrow2", "trash");
        echo Ht::unstash(), // clear out other script references
            $conf->make_script_file("scripts/settings.js"), "\n",

            Ht::form($conf->hoturl("=settings", "group={$group}"),
                     ["id" => "settingsform", "class" => "need-unload-protection"]),

            '<div class="leftmenu-left"><nav class="leftmenu-menu">',
            '<h1 class="leftmenu"><a href="" class="uic js-leftmenu q">Settings</a></h1>',
            '<ul class="leftmenu-list">';
        foreach ($sv->group_members("") as $gj) {
            $title = $gj->short_title ?? $gj->title;
            if ($gj->name === $group) {
                echo '<li class="leftmenu-item active">', $title, '</li>';
            } else if ($gj->title) {
                echo '<li class="leftmenu-item ui js-click-child">',
                    '<a href="', $conf->hoturl("settings", "group={$gj->name}"), '">', $title, '</a></li>';
            }
        }
        echo '</ul><div class="leftmenu-if-left if-alert mt-5">',
            Ht::submit("update", "Save changes", ["class" => "btn-primary"]),
            "</div></nav></div>\n",
            '<main class="leftmenu-content main-column">',
            '<h2 class="leftmenu">', $sv->group_title($group);
        $gj = $sv->cs()->get($group);
        if ($gj && isset($gj->title_help_group)) {
            echo " ", Ht::link(Icons::ui_solid_question(), $sv->conf->hoturl("help", "t={$gj->title_help_group}"), ["class" => "ml-1"]);
        }
        echo '</h2>';

        if (!$sv->use_req()) {
            $sv->crosscheck();
        }
        if ($conf->report_saved_messages() < 1 || $sv->use_req()) {
            // XXX this is janky (if there are any warnings saved in the session,
            // don't crosscheck) but reduces duplicate warnings
            $sv->report();
        }
        $sv->print_group(strtolower($group), true);

        echo '<div class="aab aabig mt-7">',
            '<div class="aabut">', Ht::submit("update", "Save changes", ["class" => "btn-primary"]), '</div>',
            '<div class="aabut">', Ht::submit("cancel", "Cancel", ["formnovalidate" => true]), '</div>',
            '<hr class="c"></div></main></form>', "\n";

        Ht::stash_script('hiliter_children("#settingsform")');
        $conf->footer();
    }

    static function go(Contact $user, Qrequest $qreq) {
        if (isset($qreq->cancel)) {
            $user->conf->redirect_self($qreq);
        }

        $sv = SettingValues::make_request($user, $qreq);
        $sv->set_use_req(isset($qreq->update) && $qreq->valid_post());
        $sv->session_highlight();
        if (!$sv->viewable_by_user()) {
            $user->escape();
        }

        $sp = new Settings_Page($sv, $user);
        $_SESSION["sg"] = $group = $qreq->group = $sp->choose_setting_group($qreq);
        
        if ($sv->use_req()) {
            $sp->handle_update($qreq);
        }  // 在这个函数中，执行保存setting信息的任务

        $file_path = "../rfields_setting.txt";
        if (file_exists($file_path)) {  // 不存在 就说明用户端没有上传文件 不需要更新review form
            $content = file_get_contents($file_path);  // 将整个文件内容读入到一个字符串中
            $rfs_j = '[{"id":"t01","name":"abstract field","order":1,"visibility":"au","description":"'.$content.'"}]';  // 只包含一个field的json
            // setting信息存储入database
            $sv_fields = SettingValues::make_request($sv->user, [
                "has_rf" => 1,
                "rf/1/id" => "t01",  // 或者"new"
                "rf/1/name" => "abstract field",
                // "rf/1/order" => "1",  // "1"还是1?
                "rf/1/type" => "text",
                "rf/1/description" => $content,
                "rf/1/required" => "1",
                "rf/1/visibility" => "au"
            ]);  // qreq可能还需要一些信息 关于conf？
            ($sv_fields->execute());  // 执行存储
            unlink("rfields_setting.txt");  // 删除缓存文件
        }

        $sp->print($group, $qreq);
    }
}
