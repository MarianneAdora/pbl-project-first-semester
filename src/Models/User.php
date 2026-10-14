<?php
class User {
    private $user_id;
    private $role;
    private $name;
    private $email;
    private $password_hash;
    private $deleted_at;
    private $created_at;

    public function __construct(array $data = []) {
        $this->user_id = $data['user_id'] ?? null;
        $this->role = $data['role'] ?? null;
        $this->name = $data['name'] ?? null;
        $this->email = $data['email'] ?? null;
        $this->password_hash = $data['password_hash'] ?? null;
        $this->deleted_at = $data['deleted_at'] ?? null;
        $this->created_at = $data['created_at'] ?? null;
    }

    public function getUserId() { return $this->user_id; }
    public function getRole() { return $this->role; }
    public function getName() { return $this->name; }
    public function getEmail() { return $this->email; }
    public function getPasswordHash() { return $this->password_hash; }

    public function toArray() {
        return [
            'user_id' => $this->user_id,
            'role' => $this->role,
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at
        ];
    }
}