<?php
class Question {
    private $question_id;
    private $section;
    private $question_text;
    private $question_type;
    private $passing_answer;
    private $is_knockout;
    private $reminder_text;
    private $is_active;
    private $created_by_user_id;
    private $deleted_at;
    private $created_at;

    public function __construct(array $data = []) {
        $this->question_id = $data['question_id'] ?? null;
        $this->section = $data['section'] ?? null;
        $this->question_text = $data['question_text'] ?? null;
        $this->question_type = $data['question_type'] ?? 'text';
        $this->passing_answer = $data['passing_answer'] ?? null;
        $this->is_knockout = $data['is_knockout'] ?? false;
        $this->reminder_text = $data['reminder_text'] ?? null;
        $this->is_active = $data['is_active'] ?? true;
        $this->created_by_user_id = $data['created_by_user_id'] ?? null;
        $this->deleted_at = $data['deleted_at'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
    }

    public function getQuestionId() { return $this->question_id; }
    public function getSection() { return $this->section; }
    public function getQuestionText() { return $this->question_text; }
    public function getQuestionType() { return $this->question_type; }
    public function getPassingAnswer() { return $this->passing_answer; }
    public function getIsKnockout() { return $this->is_knockout; }
    public function getReminderText() { return $this->reminder_text; }
    public function getIsActive() { return $this->is_active; }
}