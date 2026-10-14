<?php
class Schedule {
    private $schedule_id;
    private $applicant_id;
    private $preferred_date;
    private $preferred_time;
    private $final_date;
    private $final_time;
    private $venue;
    private $status;
    private $email_sent_at;
    private $created_at;
    private $updated_at;

    public function __construct(array $data = []) {
        $this->schedule_id = $data['schedule_id'] ?? null;
        $this->applicant_id = $data['applicant_id'] ?? null;
        $this->preferred_date = $data['preferred_date'] ?? null;
        $this->preferred_time = $data['preferred_time'] ?? null;
        $this->final_date = $data['final_date'] ?? null;
        $this->final_time = $data['final_time'] ?? null;
        $this->venue = $data['venue'] ?? null;
        $this->status = $data['status'] ?? 'PENDING';
        $this->email_sent_at = $data['email_sent_at'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
        $this->updated_at = $data['updated_at'] ?? null;
    }

    public function getScheduleId() { return $this->schedule_id; }
    public function getApplicantId() { return $this->applicant_id; }
    public function getPreferredDate() { return $this->preferred_date; }
    public function getPreferredTime() { return $this->preferred_time; }
    public function getFinalDate() { return $this->final_date; }
    public function getFinalTime() { return $this->final_time; }
    public function getVenue() { return $this->venue; }
    public function getStatus() { return $this->status; }
    public function getEmailSentAt() { return $this->email_sent_at; }
    public function setStatus($status) { $this->status = $status; }
    public function setFinalDate($date) { $this->final_date = $date; }
    public function setFinalTime($time) { $this->final_time = $time; }
}