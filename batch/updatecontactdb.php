<?php
// updatecontactdb.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(UpdateContactdb_Batch::make_args($argv)->run());
}

class UpdateContactdb_Batch {
    /** @var Conf */
    public $conf;
    /** @var int */
    public $cdb_confid;
    /** @var object */
    public $confrow;
    /** @var bool */
    public $papers;
    /** @var bool */
    public $users;
    /** @var bool */
    public $collaborators;
    /** @var bool */
    public $authors;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->papers = isset($arg["papers"]);
        $this->users = isset($arg["users"]);
        $this->collaborators = isset($arg["collaborators"]);
        $this->authors = isset($arg["authors"]);
        if (!$this->papers && !$this->users && !$this->collaborators && !$this->authors) {
            $this->papers = $this->users = true;
        }
    }

    /** @return ?\mysqli */
    private function try_cdb() {
        $cdb = $this->conf->contactdb();
        if (!$cdb) {
            return null;
        }
        $result = Dbl::ql($cdb, "select * from Conferences where `dbname`=?", $this->conf->dbname);
        $this->confrow = Dbl::fetch_first_object($result);
        if (!$this->confrow) {
            throw new ErrorException("Conference is not recorded in contactdb");
        }
        $this->cdb_confid = $this->confrow->confid = (int) $this->confrow->confid;
        if ($this->confrow->shortName !== $this->conf->short_name
            || $this->confrow->longName !== $this->conf->long_name) {
            Dbl::ql($cdb, "update Conferences set shortName=?, longName=? where confid=?",
                $this->conf->short_name ? : $this->confrow->shortName,
                $this->conf->long_name ? : $this->confrow->longName, $this->cdb_confid);
        }
        return $cdb;
    }

    /** @return \mysqli */
    private function cdb() {
        $cdb = $this->try_cdb();
        if (!$cdb) {
            throw new ErrorException("Conference has no contactdb");
        }
        return $cdb;
    }

    /** @param \mysqli $cdb */
    private function run_users($cdb) {
        // read current cdb roles
        $result = Dbl::ql($cdb, "select Roles.*, email, password
            from Roles
            join ContactInfo using (contactDbId)
            where confid=?", $this->cdb_confid);
        $cdb_users = [];
        while ($result && ($user = $result->fetch_object())) {
            $cdb_users[$user->email] = $user;
        }
        Dbl::free($result);

        // read current db roles
        $result = Dbl::ql($this->conf->dblink, "select ContactInfo.contactId, email, firstName, lastName, unaccentedName, disabled,
            (ContactInfo.roles
             | if(exists (select * from PaperConflict where contactId=ContactInfo.contactId and conflictType>=" . CONFLICT_AUTHOR . ")," . Contact::ROLE_AUTHOR . ",0)
             | if(exists (select * from PaperReview where contactId=ContactInfo.contactId)," . Contact::ROLE_REVIEWER . ",0)) roles,
            " . (Contact::ROLE_DBMASK | Contact::ROLE_AUTHOR | Contact::ROLE_REVIEWER) . " role_mask,
            lastLogin
            from ContactInfo");
        $cdbids = [];
        $qv = [];
        while (($u = Contact::fetch($result, $this->conf))) {
            $cdb_roles = $u->cdb_roles();
            if ($cdb_roles == 0 || $u->is_anonymous_user()) {
                continue;
            }
            $cdbu = $cdb_users[$u->email] ?? null;
            $cdbid = $cdbu ? (int) $cdbu->contactDbId : 0;
            if ($cdbu
                && (int) $cdbu->roles === $cdb_roles
                && $cdbu->activity_at) {
                /* skip */;
            } else if ($cdbu && $cdbu->password !== null) {
                $qv[] = [$cdbid, $this->cdb_confid, $cdb_roles, $u->activity_at ?? 0];
            } else {
                $cdbid = $u->update_cdb();
            }
            if ($cdbid) {
                $cdbids[] = $cdbid;
            }
        }
        Dbl::free($result);

        // perform role updates
        if (!empty($qv)) {
            Dbl::ql($cdb, "insert into Roles (contactDbId,confid,roles,activity_at) values ?v ?U on duplicate key update roles=?U(roles), activity_at=?U(activity_at)", $qv);
        }

        // remove old roles
        Dbl::ql($cdb, "delete from Roles where confid=? and contactDbId?A", $this->cdb_confid, $cdbids);
    }

    /** @param \mysqli $cdb */
    private function run_collaborators($cdb) {
        $result = Dbl::ql($this->conf->dblink, "select email, collaborators, updateTime, lastLogin from ContactInfo where collaborators is not null and collaborators!=''");
        while (($row = $result->fetch_row())) {
            $time = (int) $row[2] ? : (int) $row[3];
            if ($time > 0) {
                Dbl::ql($cdb, "update ContactInfo set collaborators=?, updateTime=? where email=? and (collaborators is null or collaborators='' or updateTime<?)", $row[1], $time, $row[0], $time);
            }
        }
        Dbl::free($result);
    }

    function run_authors() {
        $authors = $papers = [];
        $prows = $this->conf->paper_set(["minimal" => true, "authorInformation" => true]);
        foreach ($prows as $prow) {
            foreach ($prow->author_list() as $au) {
                if ($au->email !== "" && validate_email($au->email)) {
                    $lemail = strtolower($au->email);
                    if (!isset($authors[$lemail])) {
                        $authors[$lemail] = clone $au;
                    } else {
                        $authors[$lemail]->merge($au);
                    }
                    $papers[$lemail][] = $prow->paperId;
                }
            }
        }

        $emails = array_keys($authors);
        $pemails = $this->conf->resolve_primary_emails($emails);
        $this->conf->prefetch_users_by_email($pemails);
        $this->conf->prefetch_cdb_users_by_email($pemails);
        $cdb = $this->conf->contactdb();

        $n = count($emails);
        for ($i = 0; $i !== $n; ++$i) {
            $au = $authors[$emails[$i]];
            if (!$this->conf->user_by_email($pemails[$i], USER_SLICE)) {
                if (strcasecmp($au->email, $pemails[$i]) !== 0) {
                    // try to preserve case of original email
                    $au->email = $pemails[$i];
                }
                $u = Contact::make_keyed($this->conf, [
                    "email" => $au->email,
                    "firstName" => $au->firstName,
                    "lastName" => $au->lastName,
                    "affiliation" => $au->affiliation,
                    "disablement" => Contact::DISABLEMENT_PLACEHOLDER
                ])->store();
                // NB: Contact::store() creates CONFLICT_AUTHOR records.
                $u->update_cdb();
            }
        }
    }


    /** @param \mysqli $cdb */
    private function run_papers($cdb) {
        $result = Dbl::ql($this->conf->dblink, "select paperId, title, timeSubmitted from Paper");
        $max_submitted = 0;
        $pids = [];
        $qv = [];
        while (($row = $result->fetch_row())) {
            $qv[] = [$this->cdb_confid, $row[0], $row[1]];
            $pids[] = $row[0];
            $max_submitted = max($max_submitted, (int) $row[2]);
        }
        Dbl::free($result);

        if (!empty($qv)) {
            Dbl::ql($cdb, "insert into ConferencePapers (confid,paperId,title) values ?v ?U on duplicate key update title=?U(title)", $qv);
        }
        Dbl::ql($cdb, "delete from ConferencePapers where confid=? and paperId?A", $this->cdb_confid, $pids);
        if ($this->confrow->last_submission_at != $max_submitted) {
            Dbl::ql($cdb, "update Conferences set last_submission_at=greatest(coalesce(last_submission_at,0), ?) where confid=?", $max_submitted, $this->cdb_confid);
        }
    }

    /** @return int */
    function run() {
        $cdb = null;
        if ($this->authors) {
            $this->run_authors();
        }
        if ($this->users) {
            $cdb = $cdb ?? $this->cdb();
            $this->run_users($cdb);
        }
        if ($this->collaborators) {
            $cdb = $cdb ?? $this->cdb();
            $this->run_collaborators($cdb);
        }
        if ($this->papers) {
            $cdb = $cdb ?? $this->cdb();
            $this->run_papers($cdb);
        }
        return 0;
    }

    /** @param list<string> $argv
     * @return UpdateContactdb_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "papers,p",
            "users,u",
            "collaborators",
            "authors"
        )->description("Update HotCRP contactdb for a conference.
Usage: php batch/updatecontactdb.php [-n CONFID | --config CONFIG] [--papers] [--users] [--collaborators] [--authors]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new UpdateContactdb_Batch($conf, $arg);
    }
}
