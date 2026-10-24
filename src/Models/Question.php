<?php
class Question {
    private $question_id;
    private $section_no;
    private $section;
    private $question_text;
    private $question_type;
    private $answer_config;
    private $passing_answer;
    private $is_knockout;
    private $is_consent;
    private $is_data_protection;
    private $reminder_text;
    private $is_active;
    private $created_by_user_id;
    private $deleted_at;
    private $created_at;

    public function __construct(array $data = []) {
        $this->question_id         = $data['question_id']         ?? null;
        $this->section_no          = $data['section_no']          ?? null;
        $this->section             = $data['section']             ?? null;
        $this->question_text       = $data['question_text']       ?? null;
        $this->question_type       = $data['question_type']       ?? null;
        $this->answer_config       = $data['answer_config']       ?? null;
        $this->passing_answer      = $data['passing_answer']      ?? null;
        $this->is_knockout         = $data['is_knockout']         ?? false;
        $this->is_consent          = $data['is_consent']          ?? false;
        $this->is_data_protection  = $data['is_data_protection']  ?? false;
        $this->reminder_text       = $data['reminder_text']       ?? null;
        $this->is_active           = $data['is_active']           ?? true;
        $this->created_by_user_id  = $data['created_by_user_id']  ?? null;
        $this->deleted_at          = $data['deleted_at']          ?? null;
        $this->created_at          = $data['created_at']          ?? null;
    }

    public function getQuestionId()        { return $this->question_id; }
    public function getSectionNo()         { return $this->section_no; }
    public function getSection()           { return $this->section; }
    public function getQuestionText()      { return $this->question_text; }
    public function getQuestionType()      { return $this->question_type; }
    public function getAnswerConfig()      { return $this->answer_config; }
    public function getPassingAnswer()     { return $this->passing_answer; }
    public function getIsKnockout()        { return $this->is_knockout; }
    public function getIsConsent()         { return $this->is_consent; }
    public function getIsDataProtection()  { return $this->is_data_protection; }
    public function getReminderText()      { return $this->reminder_text; }
    public function getIsActive()          { return $this->is_active; }
}
