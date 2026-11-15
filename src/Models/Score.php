<?php
class Score {
    private $score_id;
    private $response_id;
    private $reviewed_by_user_id;
    private $questionnaire_result;
    private $risk_score;
    private $risk_flags_json;
    private $final_decision;
    private $reviewed_at;

    public function __construct(array $data = []) {
        $this->score_id = $data['score_id'] ?? null;
        $this->response_id = $data['response_id'] ?? null;
        $this->reviewed_by_user_id = $data['reviewed_by_user_id'] ?? null;
        $this->questionnaire_result = $data['questionnaire_result'] ?? null;
        $this->risk_score = $data['risk_score'] ?? 0.00;
        $this->risk_flags_json = isset($data['risk_flags_json']) ? json_decode($data['risk_flags_json'], true) : [];
        $this->final_decision = $data['final_decision'] ?? null;
        $this->reviewed_at = $data['reviewed_at'] ?? null;
    }

    public function getScoreId() { return $this->score_id; }
    public function getResponseId() { return $this->response_id; }
    public function getReviewedByUserId() { return $this->reviewed_by_user_id; }
    public function getQuestionnaireResult() { return $this->questionnaire_result; }
    public function getRiskScore() { return $this->risk_score; }
    public function getRiskFlagsJson() { return $this->risk_flags_json; }
    public function getFinalDecision() { return $this->final_decision; }
    public function getReviewedAt() { return $this->reviewed_at; }
}