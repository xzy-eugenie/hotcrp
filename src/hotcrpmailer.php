<?php
// hotcrpmailer.php -- HotCRP mail template manager
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class HotCRPMailPreparation extends MailPreparation {
    /** @var int */
    public $paperId = -1;
    /** @var bool */
    public $author_recipient = false;
    /** @var int */
    public $paper_expansions = 0;
    /** @var int */
    public $combination_type = 0;
    /** @var bool */
    public $fake = false;
    /** @var ?HotCRPMailPreparation */
    public $censored_preparation; // used in mail tool

    /** @param Conf $conf
     * @param Contact|Author $recipient */
    function __construct($conf, $recipient) {
        parent::__construct($conf, $recipient);
    }
    /** @param MailPreparation $p
     * @return bool */
    function can_merge($p) {
        return parent::can_merge($p)
            && $p instanceof HotCRPMailPreparation
            && $this->combination_type == $p->combination_type
            && (($this->combination_type == 2
                 && !$this->paper_expansions
                 && !$p->paper_expansions)
                || ($this->author_recipient === $p->author_recipient
                    && $this->combination_type != 0
                    && $this->paperId === $p->paperId)
                || ($this->author_recipient === $p->author_recipient
                    && $this->to === $p->to));
    }
}

class HotCRPMailer extends Mailer {
    /** @var array<string,Contact|Author> */
    protected $contacts = [];
    /** @var ?Contact */
    protected $permuser;

    /** @var ?PaperInfo */
    protected $row;
    /** @var ?ReviewInfo */
    protected $rrow;
    /** @var bool */
    protected $rrow_unsubmitted = false;
    /** @var ?CommentInfo */
    protected $comment_row;
    /** @var ?int */
    protected $newrev_since;
    /** @var bool */
    protected $no_send = false;
    /** @var int */
    public $combination_type = 0;

    protected $_statistics = null;


    /** @param ?Contact $recipient */
    function __construct(Conf $conf, $recipient = null, $rest = []) {
        parent::__construct($conf);
        $this->reset($recipient, $rest);
        if (isset($rest["combination_type"])) {
            $this->combination_type = $rest["combination_type"];
        }
    }

    /** @param ?Contact $recipient */
    function reset($recipient = null, $rest = []) {
        parent::reset($recipient, $rest);
        if ($recipient) {
            assert($recipient instanceof Contact);
            assert(!($recipient->overrides() & Contact::OVERRIDE_CONFLICT));
        }
        foreach (["requester", "reviewer", "other"] as $k) {
            $this->contacts[$k] = $rest["{$k}_contact"] ?? null;
        }
        $this->row = $rest["prow"] ?? null;
        assert(!$this->row || $this->row->paperId > 0);
        $this->rrow = $rest["rrow"] ?? null;
        $this->comment_row = $rest["comment_row"] ?? null;
        $this->newrev_since = $rest["newrev_since"] ?? null;
        $this->rrow_unsubmitted = !!($rest["rrow_unsubmitted"] ?? false);
        $this->no_send = !!($rest["no_send"] ?? false);
        if (($rest["author_permission"] ?? false) && $this->row) {
            $this->permuser = $this->row->author_view_user();
        } else {
            $this->permuser = $this->recipient;
        }
        // Infer reviewer contact from rrow/comment_row
        if (!$this->contacts["reviewer"]) {
            if ($this->rrow && $this->rrow->email !== null) {
                $this->contacts["reviewer"] = new Author($this->rrow);
            } else if ($this->comment_row && $this->comment_row->email !== null) {
                $this->contacts["reviewer"] = new Author($this->comment_row);
            }
        }
        // Do not put passwords in email that is cc'd elsewhere
        if ((($rest["cc"] ?? null) || ($rest["bcc"] ?? null))
            && (!$this->censor || $this->censor === self::CENSOR_DISPLAY)) {
            $this->censor = self::CENSOR_ALL;
        }
    }


    // expansion helpers
    private function _expand_reviewer($type, $isbool) {
        if (!($c = $this->contacts["reviewer"])) {
            return false;
        }
        if ($this->row
            && $this->rrow
            && $this->conf->is_review_blind((bool) $this->rrow->reviewBlind)
            && !$this->permuser->privChair
            && !$this->permuser->can_view_review_identity($this->row, $this->rrow)) {
            if ($isbool) {
                return false;
            } else if ($this->context == self::CONTEXT_EMAIL) {
                return "<hidden>";
            } else {
                return "Hidden for anonymous review";
            }
        }
        return $this->expand_user($c, $type);
    }

    /** @return Tagger */
    private function tagger()  {
        return new Tagger($this->recipient);
    }

    private function get_reviews() {
        $old_overrides = $this->permuser->overrides();
        if ($this->conf->_au_seerev === null) { /* assume sender wanted to override */
            $this->permuser->add_overrides(Contact::OVERRIDE_AU_SEEREV);
        }
        assert(($old_overrides & contact::OVERRIDE_CONFLICT) === 0);

        if ($this->rrow) {
            $rrows = [$this->rrow];
        } else {
            $this->row->ensure_full_reviews();
            $rrows = $this->row->reviews_as_display();
        }

        $text = "";
        $rf = $this->conf->review_form();
        foreach ($rrows as $rrow) {
            if (($rrow->reviewStatus >= ReviewInfo::RS_COMPLETED
                 || ($rrow == $this->rrow && $this->rrow_unsubmitted))
                && $this->permuser->can_view_review($this->row, $rrow)) {
                if ($text !== "") {
                    $text .= "\n\n*" . str_repeat(" *", 37) . "\n\n\n";
                }
                $flags = ReviewForm::UNPARSE_NO_TITLE;
                if ($this->no_send) {
                    $flags |= ReviewForm::UNPARSE_NO_AUTHOR_SEEN;
                }
                $text .= $rf->unparse_text($this->row, $rrow, $this->permuser, $flags);
            }
        }

        $this->permuser->set_overrides($old_overrides);
        return $text;
    }

    private function get_comments($tag) {
        $old_overrides = $this->permuser->overrides();
        if ($this->conf->_au_seerev === null) { /* assume sender wanted to override */
            $this->permuser->add_overrides(Contact::OVERRIDE_AU_SEEREV);
        }
        assert(($old_overrides & Contact::OVERRIDE_CONFLICT) === 0);

        if ($this->comment_row) {
            $crows = [$this->comment_row];
        } else {
            $crows = $this->row->all_comments();
        }

        $crows = array_filter($crows, function ($crow) use ($tag) {
            return (!$tag || $crow->has_tag($tag))
                && $this->permuser->can_view_comment($this->row, $crow);
        });

        $flags = ReviewForm::UNPARSE_NO_TITLE;
        if ($this->flowed) {
            $flags |= ReviewForm::UNPARSE_FLOWED;
        }
        $text = "";
        if (count($crows) > 1) {
            $text .= "Comments\n" . str_repeat("=", 75) . "\n";
        }
        foreach ($crows as $crow) {
            if ($text !== "") {
                $text .= "\n";
            }
            $text .= $crow->unparse_text($this->permuser, $flags);
        }

        $this->permuser->set_overrides($old_overrides);
        return $text;
    }

    private function get_new_assignments($contact) {
        $since = "";
        if ($this->newrev_since) {
            $since = " and r.timeRequested>=$this->newrev_since";
        }
        $result = $this->conf->qe("select r.paperId, p.title
                from PaperReview r join Paper p using (paperId)
                where r.contactId=" . $contact->contactId . "
                and r.timeRequested>r.timeRequestNotified$since
                and r.reviewSubmitted is null
                and r.reviewNeedsSubmit!=0
                and p.timeSubmitted>0
                order by r.paperId");
        $text = "";
        while (($row = $result->fetch_row())) {
            $text .= ($text ? "\n#" : "#") . $row[0] . " " . $row[1];
        }
        Dbl::free($result);
        return $text;
    }


    function infer_user_name($r, $contact) {
        // If user hasn't entered a name, try to infer it from author records
        if ($this->row && $this->row->paperId > 0) {
            $e1 = $contact->email ?? "";
            $e2 = $contact->preferredEmail ?? "";
            foreach ($this->row->author_list() as $au) {
                if (($au->firstName !== "" || $au->lastName !== "")
                    && $au->email !== ""
                    && (strcasecmp($au->email, $e1) === 0
                        || strcasecmp($au->email, $e2) === 0)) {
                    $r->firstName = $au->firstName;
                    $r->lastName = $au->lastName;
                    return;
                }
            }
        }
    }

    private function guess_reviewdeadline() {
        if ($this->row
            && ($rrows = $this->row->reviews_by_user($this->recipient))) {
            $rrow0 = $rrow1 = null;
            foreach ($rrows as $rrow) {
                if (($dl = $rrow->deadline())) {
                    if (!$rrow0 || $rrow0->deadline() > $dl) {
                        $rrow0 = $rrow;
                    }
                    if ($rrow->reviewStatus < ReviewInfo::RS_DELIVERED
                        && (!$rrow1 || $rrow1->deadline() > $dl)) {
                        $rrow1 = $rrow;
                    }
                }
            }
            if ($rrow0 || $rrow1) {
                return ($rrow1 ?? $rrow0)->deadline_name();
            }
        }
        if ($this->recipient && $this->recipient->isPC) {
            $bestdl = $bestdln = null;
            foreach ($this->conf->defined_rounds() as $i => $round_name) {
                $dln = "pcrev_soft" . ($i ? "_{$i}" : "");
                if (($dl = $this->conf->setting($dln))) {
                    if (!$bestdl
                        || ($bestdl < Conf::$now
                            ? $dl < $bestdl || $dl >= Conf::$now
                            : $dl >= Conf::$now && $dl < $bestdl)) {
                        $bestdl = $dl;
                        $bestdln = $dln;
                    }
                }
            }
            return $bestdln;
        } else {
            return null;
        }
    }

    function kw_deadline($args, $isbool, $uf) {
        if ($uf->is_review && $args) {
            $args .= "rev_soft";
        } else if ($uf->is_review) {
            $args = $this->guess_reviewdeadline();
        }
        if ($isbool) {
            return $args && $this->conf->setting($args) > 0;
        } else if ($args) {
            return $this->conf->unparse_setting_time($args);
        } else {
            return null;
        }
    }
    function kw_statistic($args, $isbool, $uf) {
        if ($this->_statistics === null) {
            $this->_statistics = $this->conf->count_submitted_accepted();
        }
        return $this->_statistics[$uf->statindex];
    }
    function kw_reviewercontact($args, $isbool, $uf) {
        if ($uf->match_data[1] === "REVIEWER") {
            if (($x = $this->_expand_reviewer($uf->match_data[2], $isbool)) !== false) {
                return $x;
            }
        } else if (($u = $this->contacts[strtolower($uf->match_data[1])])) {
            return $this->expand_user($u, $uf->match_data[2]);
        }
        return $isbool ? false : null;
    }

    function kw_newassignments() {
        return $this->get_new_assignments($this->recipient);
    }
    function kw_haspaper() {
        if ($this->row && $this->row->paperId > 0) {
            if ($this->preparation
                && $this->preparation instanceof HotCRPMailPreparation) {
                ++$this->preparation->paper_expansions;
            }
            return true;
        } else {
            return false;
        }
    }
    function kw_hasreview() {
        return !!$this->rrow;
    }

    function kw_title() {
        return $this->row->title;
    }
    function kw_titlehint() {
        if (($tw = UnicodeHelper::utf8_abbreviate($this->row->title, 40))) {
            return "\"$tw\"";
        } else {
            return "";
        }
    }
    function kw_abstract() {
        return $this->row->abstract_text();
    }
    function kw_pid() {
        return $this->row->paperId;
    }
    function kw_authors($args, $isbool) {
        if (!$this->permuser->is_root_user()
            && !$this->permuser->can_view_authors($this->row)) {
            return $isbool ? false : "Hidden for anonymous review";
        }
        $t = array_map(function ($a) { return $a->name(NAME_P|NAME_A); }, $this->row->author_list());
        return join(";\n", $t);
    }
    function kw_authorviewcapability($args, $isbool) {
        $this->sensitive = true;
        if ($this->conf->opt("disableCapabilities")
            || $this->censor === self::CENSOR_ALL) {
            return "";
        }
        if ($this->row
            && isset($this->row->capVersion)
            && $this->row->has_author($this->recipient)) {
            if (!$this->censor) {
                return "cap=" . AuthorView_Capability::make($this->row);
            } else if ($this->censor === self::CENSOR_DISPLAY) {
                return "cap=HIDDEN";
            }
        }
        return null;
    }
    function kw_decision($args, $isbool) {
        if ($this->row->outcome === 0 && $isbool) {
            return false;
        } else {
            return $this->row->decision()->name;
        }
    }
    function kw_tagvalue($args, $isbool, $uf) {
        $tag = isset($uf->match_data) ? $uf->match_data[1] : $args;
        $tag = $this->tagger()->check($tag, Tagger::NOVALUE | Tagger::NOPRIVATE);
        if (!$tag) {
            return null;
        }
        $value = $this->row->tag_value($tag);
        if ($isbool) {
            return $value !== null;
        } else if ($value !== null) {
            return (string) $value;
        } else {
            $this->warning_at($uf->input_string ?? null, "<0>Submission #{$this->row->paperId} has no #{$tag} tag");
            return "(none)";
        }
    }
    function kw_is_paperfield($uf) {
        $uf->option = $this->conf->options()->find($uf->match_data[1]);
        return !!$uf->option && $uf->option->can_render(FieldRender::CFMAIL);
    }
    function kw_paperfield($args, $isbool, $uf) {
        if (!$this->permuser->can_view_option($this->row, $uf->option)
            || !($ov = $this->row->option($uf->option))) {
            return $isbool ? false : "";
        } else {
            $fr = new FieldRender(FieldRender::CFMAIL, $this->permuser);
            $uf->option->render($fr, $ov);
            if ($isbool) {
                return ($fr->value ?? "") !== "";
            } else {
                return (string) $fr->value;
            }
        }
    }
    function kw_paperpc($args, $isbool, $uf) {
        $k = $uf->pctype . "ContactId";
        $cid = $this->row->$k;
        if ($cid > 0 && ($u = $this->conf->user_by_id($cid, USER_SLICE))) {
            return $this->expand_user($u, $uf->userx);
        } else if ($isbool)  {
            return false;
        } else if ($this->context === self::CONTEXT_EMAIL
                   || $uf->userx === "EMAIL") {
            return "<none>";
        } else {
            return "(no $uf->pctype assigned)";
        }
    }
    function kw_reviewname($args) {
        $s = $args === "SUBJECT";
        if ($this->rrow && $this->rrow->reviewOrdinal) {
            return ($s ? "review #" : "Review #") . $this->row->paperId . unparse_latin_ordinal($this->rrow->reviewOrdinal);
        } else {
            return ($s ? "review" : "A review");
        }
    }
    function kw_reviewid($args, $isbool) {
        if ($isbool && !$this->rrow) {
            return false;
        } else {
            return $this->rrow ? $this->rrow->reviewId : "";
        }
    }
    function kw_reviewacceptor() {
        if ($this->rrow && ($tok = ReviewAccept_Capability::make($this->rrow, true))) {
            return $tok->salt;
        } else {
            return false;
        }
    }
    function kw_reviews() {
        return $this->get_reviews();
    }
    function kw_comments($args, $isbool) {
        $tag = null;
        if ($args === ""
            || ($tag = $this->tagger()->check($args, Tagger::NOVALUE))) {
            return $this->get_comments($tag);
        } else {
            return null;
        }
    }

    function kw_ims_expand_authors($args, $isbool) {
        preg_match('/\A\s*(.*?)\s*(?:|,\s*(\d+)\s*)\z/', $args, $m);
        if ($m[1] === "Authors") {
            $nau = 0;
            if ($this->row
                && ($this->permuser->is_root_user()
                    || $this->permuser->can_view_authors($this->row))) {
                $nau = count($this->row->author_list());
            }
            $t = $this->conf->_c("mail", $m[1], $nau);
        } else {
            $t = $this->conf->_c("mail", $m[1]);
        }
        if (($n = (int) ($m[2] ?? 0)) && strlen($t) < $n) {
            $t = str_repeat(" ", $n - strlen($t)) . $t;
        }
        return $t;
    }


    function unexpanded_warning_at($text) {
        if (preg_match('/\A%(?:NUMBER|TITLE|PAPER|AUTHOR|REVIEW|COMMENT)/', $text)) {
            $this->warning_at($text, "<0>Reference not expanded because this mail isn’t linked to submissions or reviews");
        } else if (preg_match('/\A%AUTHORVIEWCAPABILITY/', $text)) {
            $this->warning_at($text, "<0>Reference not expanded because this mail isn’t meant for submission authors");
        } else {
            parent::unexpanded_warning_at($text);
        }
    }

    /** @return HotCRPMailPreparation */
    function prepare($template, $rest = []) {
        assert($this->recipient && $this->recipient->email);
        $prep = new HotCRPMailPreparation($this->conf, $this->recipient);
        if ($this->row && ($this->row->paperId ?? 0) > 0) {
            $prep->paperId = $this->row->paperId;
            $prep->author_recipient = $this->row->has_author($this->recipient);
        }
        $prep->combination_type = $this->combination_type;
        $this->populate_preparation($prep, $template, $rest);
        return $prep;
    }


    /** @param Contact $recipient
     * @param PaperInfo $prow
     * @param ?ReviewInfo $rrow
     * @return bool */
    static function check_can_view_review($recipient, $prow, $rrow) {
        assert(!($recipient->overrides() & Contact::OVERRIDE_CONFLICT));
        return $recipient->can_view_review($prow, $rrow);
    }

    /** @param Contact $recipient
     * @return ?HotCRPMailPreparation */
    static function prepare_to($recipient, $template, $rest = []) {
        $answer = null;
        if (!$recipient->is_dormant()) {
            $old_overrides = $recipient->remove_overrides(Contact::OVERRIDE_CONFLICT);
            $mailer = new HotCRPMailer($recipient->conf, $recipient, $rest);
            $checkf = $rest["check_function"] ?? null;
            if (!$checkf
                || call_user_func($checkf, $recipient, $mailer->row, $mailer->rrow)) {
                $answer = $mailer->prepare($template, $rest);
            }
            $recipient->set_overrides($old_overrides);
        }
        return $answer;
    }

    /** @param Contact $recipient
     * @return bool */
    static function send_to($recipient, $template, $rest = []) {
        if (($prep = self::prepare_to($recipient, $template, $rest))) {
            $prep->send();
        }
        return !!$prep;
    }

    /** @param string $template
     * @param PaperInfo $row
     * @return bool */
    static function send_contacts($template, $row, $rest = []) {
        $preps = $contacts = [];
        $rest["prow"] = $row;
        $rest["combination_type"] = 1;
        $rest["author_permission"] = true;
        foreach ($row->contact_followers() as $minic) {
            assert(empty($minic->review_tokens()));
            if (($p = self::prepare_to($minic, $template, $rest))) {
                $preps[] = $p;
                $contacts[] = $minic->name_h(NAME_EB);
            }
        }
        self::send_combined_preparations($preps);
        if (!empty($contacts) && ($user = $rest["confirm_message_for"] ?? null)) {
            '@phan-var-force Contact $user';
            if ($user->allow_view_authors($row)) {
                $m = $row->conf->_("<5>Notified submission contacts %#s", array_map(function ($u) {
                    return "<span class=\"nb\">{$u}</span>";
                }, $contacts));
            } else {
                $m = $row->conf->_("<0>Notified submission contact(s)");
            }
            $row->conf->success_msg($m);
        }
        return !empty($contacts);
    }

    /** @param PaperInfo $row */
    static function send_administrators($template, $row, $rest = []) {
        $preps = [];
        $rest["prow"] = $row;
        $rest["combination_type"] = 1;
        foreach ($row->administrators() as $u) {
            if (($p = self::prepare_to($u, $template, $rest))) {
                $preps[] = $p;
            }
        }
        self::send_combined_preparations($preps);
    }
}
