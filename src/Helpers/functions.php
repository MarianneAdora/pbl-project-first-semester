<?php
// src/Helpers/functions.php

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/db.php';

function getDbConnection() {
    return db();
}