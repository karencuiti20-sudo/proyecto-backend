<?php
header("Content-Type: application/json");
echo json_encode([
  "auth" => $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null
]);