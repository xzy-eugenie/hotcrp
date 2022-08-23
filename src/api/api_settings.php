<?php
// api_settings.php -- HotCRP settings API
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Settings_API {
    static function run(Contact $user, Qrequest $qreq) {
        $content = ["ok" => true];
        if ($qreq->valid_post()) {
            if (!isset($qreq->settings)) {
                return JsonResult::make_missing_error("settings");
            }
            $sv = (new SettingValues($user))->set_use_req(true);
            if (!$sv->viewable_by_user()) {
                return JsonResult::make_permission_error();
            }
            $sv->add_json_string($qreq->settings, $qreq->filename);
            $sv->parse();
            $dry_run = $qreq->dryrun || $qreq->dry_run;
            if ($dry_run) {
                $content["dry_run"] = true;
            } else {
                $sv->execute();
            }
            $content["ok"] = !$sv->has_error();
            $content["message_list"] = $sv->message_list();
            $content["updates"] = $sv->updated_fields();
            if ($dry_run || $sv->has_error()) {
                return new JsonResult($content);
            }
        }
        $sv = new SettingValues($user);
        if (!$sv->viewable_by_user()) {
            return JsonResult::make_permission_error();
        }
        $content["settings"] = $sv->all_json_oldv();
        return new JsonResult($content);
    }

    /** @param SettingValues $sv
     * @param Si $si
     * @param array &$x */
    static private function export_si($sv, $si, &$x) {
        $x["type"] = $si->type;
        if ($si->subtype) {
            $x["subtype"] = $si->subtype;
        }
        if ($si->summary) {
            $x["summary"] = Ftext::ensure($si->summary, 0);
        }
        if ($si->description) {
            $x["description"] = Ftext::ensure($si->description, 0);
        }
        if (($je = $si->json_examples($sv)) !== null) {
            $x["values"] = $je;
        }
    }

    /** @param SettingInfoSet $si_set
     * @param string $pfx
     * @return list<object> */
    static private function components(SettingValues $sv, $si_set, $pfx) {
        $comp = [];
        foreach ($si_set->member_list($pfx) as $xsi) {
            if ($xsi->json_export()) {
                $x = ["name" => substr($xsi->name2, 1)];
                if (($t = $xsi->member_title($sv))) {
                    $x["title"] = "<0>{$t}";
                }
                self::export_si($sv, $xsi, $x);
                $comp[] = (object) $x;
            }
        }
        return $comp;
    }

    static function descriptions(Contact $user, Qrequest $qreq) {
        $si_set = $user->conf->si_set();
        $sv = new SettingValues($user);
        if (!$sv->viewable_by_user()) {
            return JsonResult::make_permission_error();
        }
        $si_set->ensure_descriptions();
        $m = [];
        foreach ($si_set->top_list() as $si) {
            if ($si->json_export()
                && ($si->has_title() || $si->description)) {
                $o = ["name" => $si->name];
                if (($t = $si->title($sv))) {
                    $o["title"] = "<0>{$t}";
                }
                self::export_si($sv, $si, $o);
                if (($dv = $si->initial_value($sv)) !== null) {
                    $o["default_value"] = $si->base_unparse_jsonv($dv, $sv);
                }
                if ($si->type === "oblist" || $si->type === "object") {
                    $pfx = $si->type === "oblist" ? "{$si->name}/1" : $si->name;
                    $comp = self::components($sv, $si_set, $pfx);
                    if (!empty($comp)) {
                        $o["components"] = $comp;
                    }
                }

                $m[] = $o;
            }
        }
        return new JsonResult(["ok" => true, "setting_descriptions" => $m]);
    }
}
